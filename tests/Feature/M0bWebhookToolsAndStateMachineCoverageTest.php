<?php

declare(strict_types=1);

use App\Enums\ConversationState;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\Message;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Ward;
use App\Services\Ai\AiAgentService;
use App\Services\Diagnosis\ConversationStateMachine;
use App\Services\Diagnosis\MediaIntakeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SampleImageFactory;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('verifies WhatsApp webhook GET challenge, enforces HMAC-SHA256 signature on POST, and enforces idempotency on duplicate message IDs', function (): void {
    // 1. Valid GET challenge verification
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test_verify_token&hub.challenge=challenge_998877')
        ->assertOk()
        ->assertSeeText('challenge_998877');

    // 2. Invalid GET token rejected with 403
    $this->get('/api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong_token&hub.challenge=challenge_998877')
        ->assertForbidden();

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'WABA_1',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'metadata' => ['phone_number_id' => '100000000000002'],
                            'contacts' => [
                                ['wa_id' => '263779111222', 'profile' => ['name' => 'Webhook Test Farmer']],
                            ],
                            'messages' => [
                                [
                                    'id' => 'wamid.TEST_UNIQUE_MSG_001',
                                    'from' => '263779111222',
                                    'type' => 'text',
                                    'text' => ['body' => 'Mhoroi'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    $rawJson = (string) json_encode($payload);
    $secret = (string) config('betterlink.whatsapp.app_secret', 'test_whatsapp_secret_key');
    $validSig = 'sha256=' . hash_hmac('sha256', $rawJson, $secret);

    // 3. Missing or invalid X-Hub-Signature-256 rejected with 401
    $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid_hash',
    ], $rawJson)->assertUnauthorized();

    // 4. Valid X-Hub-Signature-256 accepted (200 OK) and processes message
    $res1 = $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $validSig,
    ], $rawJson);

    $res1->assertOk()
        ->assertJsonPath('status', 'accepted')
        ->assertJsonPath('queued_messages', 1);

    // 5. Duplicate replay with same wamid is ignored by EnforceWhatsAppIdempotency middleware
    $res2 = $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => $validSig,
    ], $rawJson);

    $res2->assertOk()
        ->assertJsonPath('status', 'duplicate_ignored');
});

it('executes all 13 deterministic AI tools through AiAgentService and logs each tool call to audit_logs', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $ward = Ward::query()->where('name', 'LIKE', '%Glendale%')->firstOrFail();
    $outlet = Outlet::withoutGlobalScopes()->where('tenant_id', '=', $tenant->id)->firstOrFail();
    $product = Product::withoutGlobalScopes()->where('tenant_id', '=', $tenant->id)->firstOrFail();

    $farmer = Farmer::findOrCreateByPhone('+263779333444', 'Tool Test Farmer');
    $farmer->update(['ward_id' => $ward->id]);

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
    ]);

    $msg = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'image',
    ]);

    app(MediaIntakeService::class)->processAndStoreImage(
        SampleImageFactory::createSharpLeafImage(),
        $msg,
        $farmer
    );
    /** @var Attachment $attachment */
    $attachment = $msg->attachments()->firstOrFail();

    $agent = app(AiAgentService::class);
    $registered = $agent->registeredToolNames();

    expect($registered)->toHaveCount(14)->toContain('create_marketplace_request');

    // 1. identify_from_image
    $r1 = $agent->executeTool($conversation, 'identify_from_image', ['attachment_id' => $attachment->id]);
    expect($r1)->toHaveKey('candidates');

    // 2. search_knowledge
    $r2 = $agent->executeTool($conversation, 'search_knowledge', ['query' => 'Fall Armyworm', 'crop_code' => 'maize']);
    expect($r2['chunks'])->not->toBeEmpty();

    // 3. lookup_products
    $r3 = $agent->executeTool($conversation, 'lookup_products', ['target_code' => 'fall_armyworm', 'crop_code' => 'maize']);
    expect($r3['products'])->not->toBeEmpty();

    // 4. calculate_application_rate
    $r4 = $agent->executeTool($conversation, 'calculate_application_rate', [
        'crop_code' => 'maize',
        'nutrient_addressed' => 'N',
        'field_size_ha' => 1.0,
    ]);
    expect($r4)->toHaveKey('per_pfumvudza_station');

    // 5. get_weather
    $r5 = $agent->executeTool($conversation, 'get_weather', ['ward_id' => $ward->id]);
    expect($r5)->toHaveKey('is_safe_to_spray_now');

    // 6. update_farmer_profile
    $r6 = $agent->executeTool($conversation, 'update_farmer_profile', [
        'full_name' => 'Updated Farmer Name',
        'farming_sector' => 'a1',
        'is_pfumvudza_enrolled' => true,
        'farm_size_ha' => 2.5,
    ]);
    expect($r6['status'])->toBe('updated');

    // 7. find_nearest_outlets
    $r7 = $agent->executeTool($conversation, 'find_nearest_outlets', ['product_ids' => [$product->id]]);
    expect($r7['outlets'])->not->toBeEmpty();

    // 8. check_outlet_stock
    $r8 = $agent->executeTool($conversation, 'check_outlet_stock', [
        'outlet_id' => $outlet->id,
        'product_id' => $product->id,
    ]);
    expect($r8['in_stock'])->toBeTrue();

    // 9. create_reservation
    $r9 = $agent->executeTool($conversation, 'create_reservation', [
        'outlet_id' => $outlet->id,
        'basket_items' => [
            ['product_id' => $product->id, 'sku' => $product->sku, 'product_name' => $product->name, 'pack_size' => '100g', 'quantity' => 2, 'price_usd' => 6.50],
        ],
    ]);
    expect($r9['status'])->toBe('pending');

    // 10. request_farmer_location
    $r10 = $agent->executeTool($conversation, 'request_farmer_location', ['reason' => 'Find nearest stockist']);
    expect($r10['state'])->toBe(ConversationState::AWAITING_LOCATION->value);

    // 11. create_lead
    $r11 = $agent->executeTool($conversation, 'create_lead', ['temperature' => 'hot', 'intent_score' => 92]);
    expect($r11['temperature'])->toBe('hot');

    // 12. handoff_to_human
    $r12 = $agent->executeTool($conversation, 'handoff_to_human', [
        'handoff_type' => 'agronomist',
        'reason' => 'Complex symptom review',
    ]);
    expect($r12['status'])->toBe('pending');

    // 13. schedule_meeting
    $r13 = $agent->executeTool($conversation, 'schedule_meeting', ['meeting_type' => 'farm_visit']);
    expect($r13)->toHaveKey('ics_file_path');

    expect(AuditLog::query()->where('conversation_id', '=', $conversation->id)->where('event_type', '=', 'tool_execution')->count())->toBe(13);
});

it('rejects illegal state machine transitions with a DomainException', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263779555666', 'Guard Test Farmer');

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'state' => ConversationState::AWAITING_CONSENT,
    ]);

    $sm = app(ConversationStateMachine::class);

    // Jumping directly from AWAITING_CONSENT to ADVISORY_DELIVERED is forbidden
    expect(fn () => $sm->transitionTo($conversation, ConversationState::ADVISORY_DELIVERED, 'illegal_jump'))
        ->toThrow(DomainException::class);
});
