<?php

declare(strict_types=1);

use App\Actions\Consent\ProcessOptOutOrOptInAction;
use App\Actions\Consent\RecordFarmerConsentAction;
use App\Enums\ConversationState;
use App\Enums\DiagnosisCategory;
use App\Enums\LeadTemperature;
use App\Models\Campaign;
use App\Models\Conversation;
use App\Models\Crop;
use App\Models\Diagnosis;
use App\Models\Farmer;
use App\Models\Outlet;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Ward;
use App\Services\Crm\CrmManager;
use App\Services\Diagnosis\OutbreakDetectionService;
use App\Services\Engagement\CampaignBroadcastService;
use App\Services\Leads\HandoffRoutingService;
use App\Services\Leads\LeadQualificationService;
use App\Services\Leads\MeetingSchedulerService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(DatabaseSeeder::class);
});

it('qualifies hot buying intent with stored reasons, routes to rep with handoff_summary card and deep-link URL, and schedules an ICS meeting', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263777100100', 'Tapiwa Govere');
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::QUALIFYING_BUYING_INTENT,
    ]);

    $diagnosis = Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'farmer_id' => $farmer->id,
        'crop_id' => $maize->id,
        'category' => DiagnosisCategory::PEST,
        'field_size_ha' => 3.00,
        'final_diagnosis_name' => 'Fall Armyworm',
        'final_diagnosis_code' => 'fall_armyworm',
        'confidence' => 0.90,
    ]);

    $qualService = app(LeadQualificationService::class);
    $qualification = $qualService->evaluateBuyingIntent(
        $conversation,
        'I need to buy and spray tomorrow urgently for my 3 ha maize',
        $diagnosis
    );

    expect($qualification->temperature)->toBe(LeadTemperature::HOT)
        ->and($qualification->intentScore)->toBeGreaterThanOrEqual(75)
        ->and($qualification->scoreReasons)->toContain('urgent_purchase_window_24_48h');

    $lead = $qualService->createOrUpdateOpportunity($conversation, $qualification, $diagnosis);
    $routed = app(HandoffRoutingService::class)->routeLeadToNearestRep($conversation, $lead, $diagnosis);

    expect($routed['handoff']->status)->toBe('pending')
        ->and($routed['summary_card']->payload['open_opportunity_url'])->toContain("/admin/farmserve-zimbabwe/leads/{$lead->id}/edit");

    // Schedule a meeting and verify .ics file generation
    $rep = User::query()->findOrFail($lead->fresh()->assigned_user_id);
    $meetingService = app(MeetingSchedulerService::class);
    $slots = $meetingService->proposeSlotsForRep($rep);
    expect($slots)->toHaveCount(3);

    $meeting = $meetingService->createConfirmedMeetingWithIcs(
        $conversation,
        $rep,
        now()->addDay()->setTime(9, 0),
        'farm_visit',
        $lead
    );

    expect($meeting->status)->toBe('confirmed')
        ->and(Storage::disk('local')->exists((string) $meeting->ics_file_path))->toBeTrue();
});

it('cascades expired 30-min outlet reservations to the next nearest outlet and then to a sales rep', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263777200200', 'Memory Chuma');
    $outlets = Outlet::withoutGlobalScopes()->where('tenant_id', '=', $tenant->id)->limit(2)->get();

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::RESERVATION_PENDING,
        'context_metadata_json' => [
            'last_ranked_outlets' => [
                ['outlet_id' => $outlets[0]->id, 'outlet_name' => $outlets[0]->name, 'distance_km' => 4.2],
                ['outlet_id' => $outlets[1]->id, 'outlet_name' => $outlets[1]->name, 'distance_km' => 18.5],
            ],
        ],
    ]);

    // Create an expired reservation at Outlet #1 (sla_due_at in the past)
    $res1 = Reservation::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'outlet_id' => $outlets[0]->id,
        'farmer_id' => $farmer->id,
        'conversation_id' => $conversation->id,
        'basket_items_json' => [['product_id' => 'p1', 'pack_size' => '50kg', 'quantity' => 2]],
        'status' => 'pending',
        'sla_due_at' => now()->subMinutes(5),
    ]);

    Artisan::call('betterlink:check-slas');

    expect($res1->fresh()->status)->toBe('expired')
        // Conversation offered Outlet #2 -> OUTLET_RESULTS_SENT
        ->and($conversation->fresh()->state)->toBe(ConversationState::OUTLET_RESULTS_SENT);

    // Now create an expired reservation at Outlet #2 so all outlets have been tried
    $res2 = Reservation::withoutGlobalScopes()->create([
        'tenant_id' => $tenant->id,
        'outlet_id' => $outlets[1]->id,
        'farmer_id' => $farmer->id,
        'conversation_id' => $conversation->id,
        'basket_items_json' => [['product_id' => 'p1', 'pack_size' => '50kg', 'quantity' => 2]],
        'status' => 'pending',
        'sla_due_at' => now()->subMinutes(5),
    ]);

    Artisan::call('betterlink:check-slas');

    expect($res2->fresh()->status)->toBe('expired')
        // All outlets failed -> escalated to Sales Rep (LEAD_ROUTED_TO_REP)
        ->and($conversation->fresh()->state)->toBe(ConversationState::LEAD_ROUTED_TO_REP);
});

it('clusters 5+ ward diagnoses within 7 days into an OutbreakSignal and only broadcasts campaigns to opted-in farmers', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $glendaleWard = Ward::query()->where('name', 'LIKE', '%Glendale%')->firstOrFail();
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    $optedInFarmer = Farmer::findOrCreateByPhone('+263777300301', 'Opted In Farmer');
    $optedInFarmer->update(['ward_id' => $glendaleWard->id]);
    $convIn = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $optedInFarmer->id,
        'channel' => 'whatsapp',
    ]);
    app(RecordFarmerConsentAction::class)->execute($convIn, $optedInFarmer);

    $optedOutFarmer = Farmer::findOrCreateByPhone('+263777300302', 'Opted Out Farmer');
    $optedOutFarmer->update(['ward_id' => $glendaleWard->id]);
    $convOut = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $optedOutFarmer->id,
        'channel' => 'whatsapp',
    ]);
    app(ProcessOptOutOrOptInAction::class)->executeOptOut($convOut, $optedOutFarmer, 'STOP');

    // Create 5 Fall Armyworm diagnoses in Glendale Ward within last 7 days
    for ($i = 0; $i < 5; $i++) {
        Diagnosis::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $convIn->id,
            'farmer_id' => $optedInFarmer->id,
            'ward_id' => $glendaleWard->id,
            'crop_id' => $maize->id,
            'category' => DiagnosisCategory::PEST,
            'final_diagnosis_name' => 'Fall Armyworm',
            'final_diagnosis_code' => 'fall_armyworm',
            'confidence' => 0.88,
        ]);
    }

    $signals = app(OutbreakDetectionService::class)->scanAndClusterOutbreaks($tenant->id);
    expect($signals)->toHaveCount(1)
        ->and($signals[0]->cases_count_7d)->toBe(5)
        ->and($signals[0]->target_code)->toBe('fall_armyworm');

    // Launch the generated outbreak alert campaign -> must send to $optedInFarmer (1) and skip $optedOutFarmer
    $campaign = Campaign::withoutGlobalScopes()->findOrFail($signals[0]->alert_campaign_id);
    $sentCount = app(CampaignBroadcastService::class)->launchCampaign($campaign);

    expect($sentCount)->toBe(1);
});

it('syncs leads across all CRM adapters (standalone, csv, webhook, hubspot, zoho)', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263777400400', 'CRM Test Farmer');

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
    ]);

    $lead = app(LeadQualificationService::class)->createOrUpdateOpportunity(
        $conversation,
        app(LeadQualificationService::class)->evaluateBuyingIntent($conversation, 'Buy 50kg Compound D today')
    );

    $crmManager = app(CrmManager::class);

    foreach (['standalone', 'csv', 'webhook', 'hubspot', 'zoho'] as $adapterKey) {
        $tenant->update(['crm_config' => ['mode' => 'integrated', 'adapter' => $adapterKey]]);
        $res = $crmManager->syncLead($lead->fresh());
        expect($res['synced'])->toBeTrue()
            ->and($res['adapter'])->toBe($adapterKey)
            ->and($res['external_crm_id'])->not->toBeEmpty();
    }
});
