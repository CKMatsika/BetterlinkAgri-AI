<?php

declare(strict_types=1);

use App\Enums\ConversationMode;
use App\Enums\ConversationState;
use App\Enums\LanguageCode;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\Message;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\Ward;
use App\Services\Diagnosis\ConversationStateMachine;
use App\Services\Diagnosis\MediaIntakeService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SampleImageFactory;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('executes the complete end-to-end state machine flow: consent -> photo intake -> vision -> clarifying question -> advisory -> outlet finder -> stock reservation', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $glendaleWard = Ward::query()->where('name', 'LIKE', '%Glendale%')->firstOrFail();

    $farmer = Farmer::findOrCreateByPhone('+263775888999', 'Rumbidzai Moyo');
    $farmer->update(['ward_id' => $glendaleWard->id]);

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::CONSENT_CHECK,
        'mode' => ConversationMode::AI_ACTIVE,
        'language' => LanguageCode::ENGLISH,
    ]);

    $stateMachine = app(ConversationStateMachine::class);

    // 1. First message triggers AWAITING_CONSENT
    $msg1 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => 'Hello',
    ]);

    $turn1 = $stateMachine->handleInboundTurn($conversation, $msg1);
    expect($turn1['state'])->toBe(ConversationState::AWAITING_CONSENT)
        ->and($turn1['reply_text'])->toContain('Cyber & Data Protection Act');

    // 2. Farmer replies "1" (Yes, I agree) -> grants consent & replays "Hello" -> AWAITING_PHOTO_OR_SYMPTOMS
    $msg2 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => '1',
    ]);

    $turn2 = $stateMachine->handleInboundTurn($conversation->fresh(), $msg2);
    expect($turn2['state'])->toBe(ConversationState::AWAITING_PHOTO_OR_SYMPTOMS);

    // 3. Farmer uploads a sharp leaf photo -> VISION_IDENTIFICATION -> CLARIFYING_QUESTIONS -> AWAITING_QUESTION_ANSWER (Question 1)
    $msg3 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'image',
        'body_text' => 'Caterpillar in maize funnel',
    ]);

    $mediaService = app(MediaIntakeService::class);
    $mediaService->processAndStoreImage(SampleImageFactory::createSharpLeafImage(), $msg3, $farmer);
    /** @var Attachment $attachment */
    $attachment = $msg3->attachments()->firstOrFail();

    $turn3 = $stateMachine->handleInboundTurn($conversation->fresh(), $msg3, $attachment);
    expect($turn3['state'])->toBe(ConversationState::AWAITING_QUESTION_ANSWER)
        ->and($turn3['reply_text'])->toContain('Likely *Fall Armyworm')
        ->and($turn3['reply_text'])->toContain('Question 1:');

    // 4. Farmer answers Question 1 ("faw_signs" -> boosts confidence to 0.90 >= 0.80) -> asks missing mandatory field_size_or_pfumvudza (Question 2)
    $msg4 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => 'faw_signs',
    ]);

    $turn4 = $stateMachine->handleInboundTurn($conversation->fresh(), $msg4);
    expect($turn4['state'])->toBe(ConversationState::AWAITING_QUESTION_ANSWER)
        ->and($turn4['reply_text'])->toContain('Question 2:');

    // 5. Farmer answers Question 2 ("ha_1_0" -> 1 Hectare) -> executes deterministic tools, validates guardrails, delivers advisory, transitions to QUALIFYING_BUYING_INTENT
    $msg5 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => 'ha_1_0',
    ]);

    $turn5 = $stateMachine->handleInboundTurn($conversation->fresh(), $msg5);
    expect($turn5['state'])->toBe(ConversationState::QUALIFYING_BUYING_INTENT)
        ->and($turn5['reply_text'])->toContain('*What it is:*')
        ->and($turn5['reply_text'])->toContain('*What to do now:*')
        ->and($turn5['reply_text'])->toContain('*Product options:*')
        ->and($turn5['reply_text'])->toContain('CoragenPro Chlorantraniliprole 200 SC')
        ->and($turn5['reply_text'])->toContain('*How to apply:*')
        ->and($turn5['reply_text'])->toContain('*Safety:*')
        ->and($turn5['rich_cards'])->not->toBeEmpty();

    // 6. Farmer replies "1" (Nearest Outlets) -> resolves Glendale ward location -> OUTLET_RESULTS_SENT
    $msg6 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => '1',
    ]);

    $turn6 = $stateMachine->handleInboundTurn($conversation->fresh(), $msg6);
    expect($turn6['state'])->toBe(ConversationState::OUTLET_RESULTS_SENT)
        ->and($turn6['reply_text'])->toContain('FarmServe Glendale Agro-Branch');

    // 7. Farmer replies "HOLD 1" -> creates Reservation + Hot Lead with 30-min SLA -> RESERVATION_PENDING
    $msg7 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => 'HOLD 1',
    ]);

    $turn7 = $stateMachine->handleInboundTurn($conversation->fresh(), $msg7);
    expect($turn7['state'])->toBe(ConversationState::RESERVATION_PENDING)
        ->and($turn7['reply_text'])->toContain('Stock Hold Requested')
        ->and(Reservation::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('conversation_id', '=', $conversation->id)->count())->toBeGreaterThan(8);

    // 8. Test MIRA (Shona Opt-Out) and START (Opt-In)
    $msg8 = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => 'MIRA',
    ]);

    $turn8 = $stateMachine->handleInboundTurn($conversation->fresh(), $msg8);
    expect($turn8['state'])->toBe(ConversationState::OPTED_OUT);
});
