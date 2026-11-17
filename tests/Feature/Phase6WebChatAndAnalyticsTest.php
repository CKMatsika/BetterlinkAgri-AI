<?php

declare(strict_types=1);

use App\Enums\CurrencyCode;
use App\Enums\DiagnosisCategory;
use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\Crop;
use App\Models\Diagnosis;
use App\Models\Farmer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SalesAttribution;
use App\Models\Tenant;
use App\Services\Analytics\TenantAnalyticsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\SampleImageFactory;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('handles Web Chat Widget messages, photo uploads, context chips, and satisfaction feedback via /api/v1', function (): void {
    // 1. First web chat turn triggers consent prompt
    $res1 = $this->postJson('/api/v1/web-chat/messages', [
        'phone' => '+263778100200',
        'farmer_name' => 'Farai Nkomo',
        'message_text' => 'Hello',
        'language' => 'en',
    ], ['X-Tenant-Slug' => 'farmserve-zimbabwe']);

    $res1->assertOk()
        ->assertJsonPath('data.state', 'AWAITING_CONSENT');

    $conversationId = (string) $res1->json('data.conversation_id');

    // 2. Grant consent ("1")
    $res2 = $this->postJson('/api/v1/web-chat/messages', [
        'phone' => '+263778100200',
        'message_text' => '1',
    ], ['X-Tenant-Slug' => 'farmserve-zimbabwe']);

    $res2->assertOk()
        ->assertJsonPath('data.state', 'AWAITING_PHOTO_OR_SYMPTOMS');

    // 3. Upload base64 sharp leaf photo
    $sharpB64 = base64_encode(SampleImageFactory::createSharpLeafImage());
    $res3 = $this->postJson('/api/v1/web-chat/messages', [
        'phone' => '+263778100200',
        'message_text' => 'Yellow V-shape on older maize leaves',
        'photo_base64' => $sharpB64,
    ], ['X-Tenant-Slug' => 'farmserve-zimbabwe']);

    $res3->assertOk()
        ->assertJsonPath('data.state', 'AWAITING_QUESTION_ANSWER')
        ->assertJsonPath('data.context_chip', 'Maize / v4_v6_topdress / nutrient_deficiency');

    // 4. Answer clarifying questions to reach advisory
    $this->postJson('/api/v1/web-chat/messages', [
        'phone' => '+263778100200',
        'message_text' => 'old_v_midrib',
    ], ['X-Tenant-Slug' => 'farmserve-zimbabwe'])->assertOk();

    $resAdvisory = $this->postJson('/api/v1/web-chat/messages', [
        'phone' => '+263778100200',
        'message_text' => 'pfumvudza_1',
    ], ['X-Tenant-Slug' => 'farmserve-zimbabwe']);

    $resAdvisory->assertOk()
        ->assertJsonPath('data.state', 'QUALIFYING_BUYING_INTENT');

    // 5. Submit 1-10 satisfaction rating and thumbs up
    $feedbackRes = $this->postJson('/api/v1/web-chat/feedback', [
        'conversation_id' => $conversationId,
        'thumbs_up' => true,
        'rating' => 10,
        'feedback_text' => 'Very clear Pfumvudza cup rates!',
    ]);

    $feedbackRes->assertOk()
        ->assertJsonPath('status', 'feedback_recorded');
});

it('handles SMS and USSD fallback gateway requests', function (): void {
    $res = $this->postJson('/api/v1/webhooks/sms-ussd', [
        'from' => '+263778300400',
        'text' => 'Mhoroi',
        'channel' => 'ussd',
    ]);

    $res->assertOk()
        ->assertJsonPath('channel', 'ussd')
        ->assertJsonPath('state', 'AWAITING_CONSENT');

    expect((string) $res->json('ussd_session'))->toStartWith('CON ');
});

it('computes all Section 14 analytics metrics including AI accuracy, satisfaction, first AI sales, upsell uplift, and stock freshness', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263778500600', 'Analytics Farmer');
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();
    $outlet = Outlet::withoutGlobalScopes()->where('tenant_id', '=', $tenant->id)->firstOrFail();
    $product = Product::withoutGlobalScopes()->where('tenant_id', '=', $tenant->id)->firstOrFail();

    $conv = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
    ]);

    // 1 confirmed accurate diagnosis + 1 overridden diagnosis => 50.0% AI Accuracy
    $d1 = Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'farmer_id' => $farmer->id,
        'crop_id' => $maize->id,
        'category' => DiagnosisCategory::PEST,
        'final_diagnosis_code' => 'fall_armyworm',
        'final_diagnosis_name' => 'Fall Armyworm',
        'confidence' => 0.90,
        'agronomist_confirmed' => true,
    ]);

    Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'farmer_id' => $farmer->id,
        'crop_id' => $maize->id,
        'category' => DiagnosisCategory::PEST,
        'final_diagnosis_code' => 'maize_stalk_borer',
        'final_diagnosis_name' => 'Maize Stalk Borer',
        'confidence' => 0.62,
        'agronomist_confirmed' => false,
    ]);

    Advisory::query()->create([
        'tenant_id' => $tenant->id,
        'diagnosis_id' => $d1->id,
        'advisory_type' => 'pest',
        'steps_json' => ['today' => 'Scout'],
        'safety_warnings_text' => 'Wear PPE',
        'rendered_message_text' => 'Advisory',
        'farmer_thumbs_up' => true,
        'farmer_rating' => 9,
    ]);

    $res = Reservation::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'outlet_id' => $outlet->id,
        'farmer_id' => $farmer->id,
        'conversation_id' => $conv->id,
        'diagnosis_id' => $d1->id,
        'basket_items_json' => [],
        'status' => 'picked_up',
        'sla_due_at' => now()->addMinutes(30),
        'confirmed_at' => now()->addMinutes(6),
    ]);

    SalesAttribution::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'diagnosis_id' => $d1->id,
        'reservation_id' => $res->id,
        'outlet_id' => $outlet->id,
        'product_id' => $product->id,
        'farmer_id' => $farmer->id,
        'quantity_sold' => 2,
        'total_amount' => 64.00,
        'currency' => CurrencyCode::USD,
        'is_first_ai_engaged_sale' => true,
        'is_upsell_on_engaged_farmer' => false,
        'attributed_at' => now(),
    ]);

    SalesAttribution::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conv->id,
        'diagnosis_id' => $d1->id,
        'reservation_id' => $res->id,
        'outlet_id' => $outlet->id,
        'product_id' => $product->id,
        'farmer_id' => $farmer->id,
        'quantity_sold' => 1,
        'total_amount' => 38.00,
        'currency' => CurrencyCode::USD,
        'is_first_ai_engaged_sale' => false,
        'is_upsell_on_engaged_farmer' => true,
        'attributed_at' => now(),
    ]);

    $metrics = app(TenantAnalyticsService::class)->computeDashboardMetrics($tenant->id);

    expect($metrics['farmers_reached'])->toBe(1)
        ->and($metrics['total_diagnoses'])->toBe(2)
        ->and($metrics['ai_accuracy_pct'])->toBe(50.0)
        ->and($metrics['farmer_thumbs_up_pct'])->toBe(100.0)
        ->and($metrics['farmer_avg_rating_10'])->toBe(9.0)
        ->and($metrics['new_sales_first_engaged_ai_usd'])->toBe(64.00)
        ->and($metrics['upsell_uplift_engaged_farmers_usd'])->toBe(38.00)
        ->and($metrics['total_attributed_sales_usd'])->toBe(102.00)
        ->and($metrics['referral_to_reservation_pct'])->toBe(50.0)
        ->and($metrics['outlet_freshness_report'])->not->toBeEmpty();
});
