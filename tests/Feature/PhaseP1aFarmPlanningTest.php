<?php

declare(strict_types=1);

use App\Enums\ConversationState;
use App\Enums\CurrencyCode;
use App\Enums\LanguageCode;
use App\Enums\PlanState;
use App\Enums\ViabilityVerdict;
use App\Models\Benchmark;
use App\Models\ConsentEvent;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\MarketPrice;
use App\Models\Message;
use App\Models\PlanEnterprise;
use App\Models\PlanRequirement;
use App\Models\PlanReview;
use App\Models\Tenant;
use App\Services\Diagnosis\ConversationStateMachine;
use App\Services\Planning\PlanFinancialEngine;
use App\Services\Planning\PlanInterviewEngine;
use App\Services\Planning\PlanStateMachine;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanningTestFixturesSeeder;

beforeEach(function (): void {
    $this->seed([DatabaseSeeder::class, PlanningTestFixturesSeeder::class]);
});

it('derives current consent from append-only consent_events table', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263771000111', 'Consent Ledger Farmer');

    expect(ConsentEvent::hasActiveConsent($tenant->id, $farmer->id, 'plan_share_bank'))->toBeFalse();

    ConsentEvent::record($tenant->id, $farmer->id, 'plan_share_bank', 'granted', 'web_chat', 'planner_ui');
    expect(ConsentEvent::hasActiveConsent($tenant->id, $farmer->id, 'plan_share_bank'))->toBeTrue();

    ConsentEvent::record($tenant->id, $farmer->id, 'plan_share_bank', 'revoked', 'whatsapp', 'farmer_reply');
    expect(ConsentEvent::hasActiveConsent($tenant->id, $farmer->id, 'plan_share_bank'))->toBeFalse();

    // Append-only: both events remain in the ledger
    expect(ConsentEvent::query()->where('farmer_id', '=', $farmer->id)->where('scope', '=', 'plan_share_bank')->count())->toBe(2);
});

it('supports multiple resumable plans per farmer and survives mid-interview pest photo interruptions', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263771000222', 'Multi-Plan Farmer');
    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();
    $maize = PlanEnterprise::query()->where('code', '=', 'maize')->firstOrFail();

    $plan1 = FarmPlan::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $broilers->id,
        'title' => '500 Broilers Batch 1 (test fixture, not agronomic data)',
        'scale_value' => 500,
        'scale_unit' => 'birds',
    ]);

    $plan2 = FarmPlan::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $maize->id,
        'title' => '2 Ha Maize Plan (test fixture, not agronomic data)',
        'scale_value' => 2,
        'scale_unit' => 'hectares',
    ]);

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
    ]);

    $planSm = app(PlanStateMachine::class);
    $interview = app(PlanInterviewEngine::class);

    $planSm->attachToConversation($conversation, $plan1);

    // Answer Question 1 on Plan 1
    $q1 = $interview->getNextQuestionOrComplete($plan1, LanguageCode::ENGLISH);
    expect($q1['completed'])->toBeFalse()
        ->and($q1['question']['key'])->toBe('broiler_birds_per_batch');

    $interview->recordAnswer($plan1, 'broiler_birds_per_batch', '500');

    // Mid-interview interruption: Farmer sends a text symptom ("worm in maize funnel")
    $diagSm = app(ConversationStateMachine::class);
    $msgConsent = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => '1',
    ]);
    $conversation->update(['state' => ConversationState::AWAITING_CONSENT]);
    $diagSm->handleInboundTurn($conversation, $msgConsent);

    $msgPest = Message::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'direction' => 'inbound',
        'sender_type' => 'farmer',
        'message_type' => 'text',
        'body_text' => 'Caterpillar in maize funnel',
    ]);
    $diagSm->handleInboundTurn($conversation->fresh(), $msgPest);

    // Verify Plan 1 state & pointer survived the interruption and resumes at Question 2
    expect($conversation->fresh()->active_farm_plan_id)->toBe($plan1->id)
        ->and($plan1->fresh()->state)->toBe(PlanState::INTERVIEW)
        ->and($plan2->fresh()->state)->toBe(PlanState::INTERVIEW);

    $q2 = $interview->getNextQuestionOrComplete($plan1->fresh(), LanguageCode::ENGLISH);
    expect($q2['completed'])->toBeFalse()
        ->and($q2['question']['key'])->toBe('broiler_batches_per_year');

    // Test unrealistic answer flagging without blocking (12 batches/year > 8 max)
    $flagCheck = $interview->recordAnswer($plan1, 'broiler_batches_per_year', '12');
    expect($flagCheck['flagged'])->toBeTrue()
        ->and($flagCheck['flag_reason'])->toContain('exceeds typical maximum');
});

it('computes deterministic integer minor-unit broiler financials and applies the two verdict labels correctly', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263771000333', 'Financial Engine Farmer');
    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();

    // Case A: Unverified fixture benchmarks -> label MUST be "demo data, needs agronomist review" + PlanReview created
    $planA = FarmPlan::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $broilers->id,
        'title' => '500 Broilers Fixture Budget (test fixture, not agronomic data)',
        'state' => PlanState::PLAN_DRAFTED,
        'scale_value' => 500,
        'scale_unit' => 'birds',
        'currency' => CurrencyCode::USD,
        'exchange_rate_scaled' => 255000, // 25.5000
    ]);

    $engine = app(PlanFinancialEngine::class);
    $projA = $engine->runProjections($planA);

    // Exact minor-unit deterministic check (test fixture, not agronomic data):
    // Chicks: 500 * 110c = 55000c ($550)
    // Feed: 500 * 340c = 170000c ($1700)
    // Vet: 500 * 65c = 32500c ($325)
    // Working capital = 257500c ($2575), Capex = 50000c ($500), Total cost = 307500c ($3075)
    // Surviving birds (5% mortality): 475 * 650c = 308750c ($3087.50)
    // Net margin = 308750 - 307500 = +1250c ($12.50)
    expect($projA->working_capital_minor)->toBe(257500)
        ->and($projA->capital_cost_minor)->toBe(50000)
        ->and($projA->total_cost_minor)->toBe(307500)
        ->and($projA->total_revenue_minor)->toBe(308750)
        ->and($projA->net_margin_minor)->toBe(1250)
        ->and($planA->fresh()->state)->toBe(PlanState::PROJECTION_READY)
        ->and($planA->fresh()->verdict_label)->toBe('demo data, needs agronomist review')
        ->and($planA->fresh()->viability_verdict)->toBe(ViabilityVerdict::VIABLE_WITH_CONDITIONS)
        ->and(PlanReview::query()->where('farm_plan_id', '=', $planA->id)->count())->toBe(1);

    // Case B: Verify all benchmarks & market prices, then pass farmer overrides -> "based on your own figures" & NO review queued
    Benchmark::query()->update(['status' => 'verified']);
    MarketPrice::query()->update(['status' => 'verified']);

    $planB = FarmPlan::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $broilers->id,
        'title' => '500 Broilers ZiG Farmer-Supplied Budget (test fixture, not agronomic data)',
        'state' => PlanState::PLAN_DRAFTED,
        'scale_value' => 500,
        'scale_unit' => 'birds',
        'currency' => CurrencyCode::ZIG,
        'exchange_rate_scaled' => 255000, // 1 USD = 25.50 ZiG
    ]);

    $projB = $engine->runProjections($planB, [
        'market_price_live_broiler' => 18000, // Farmer-supplied ZiG 180.00 / bird
    ]);

    expect($projB->currency)->toBe(CurrencyCode::ZIG)
        ->and($projB->total_revenue_minor)->toBe(475 * 18000)
        ->and($planB->fresh()->verdict_label)->toBe('based on your own figures')
        ->and(PlanReview::query()->where('farm_plan_id', '=', $planB->id)->count())->toBe(0);
});

it('creates plan_requirements with nullable marketplace linkage columns for zero-rewrite P2', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263771000444', 'Requirements Farmer');
    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();

    $plan = FarmPlan::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $broilers->id,
        'title' => 'Requirement Linkage Plan (test fixture, not agronomic data)',
        'scale_value' => 500,
        'scale_unit' => 'birds',
    ]);

    $req = PlanRequirement::query()->create([
        'farm_plan_id' => $plan->id,
        'requirement_type' => 'livestock_booking',
        'item_code' => 'day_old_broiler_chicks',
        'item_name' => 'Day-Old Broiler Chicks',
        'quantity_scaled' => 50000, // 500.00 birds
        'unit' => 'birds',
        'needed_by_date' => now()->addDays(30)->toDateString(),
        'lead_time_days' => 21,
        'activation_at' => now()->addDays(9),
        'status' => 'scheduled',
        'marketplace_request_id' => null,
        'booking_id' => null,
        'forward_quote_id' => null,
    ]);

    expect($req->status)->toBe('scheduled')
        ->and($req->marketplace_request_id)->toBeNull()
        ->and($req->booking_id)->toBeNull()
        ->and($req->forward_quote_id)->toBeNull();
});

it('exposes /api/v1/health and reports database/extension readiness', function (): void {
    config()->set('betterlink.ai.default_provider', 'anthropic');
    $response = $this->getJson('/api/v1/health');
    $driver = \Illuminate\Support\Facades\DB::getDriverName();

    if ($driver === 'pgsql') {
        $response->assertStatus(200)->assertJsonPath('ok', true)->assertJsonPath('postgis', true)->assertJsonPath('pgvector', true);
    } else {
        $response->assertStatus(503)->assertJsonPath('ok', false)->assertJsonPath('driver', $driver);
    }
});

it('hides unverified demo numbers behind under agronomist review when demo numbers flag is false', function (): void {
    config()->set('betterlink.planning.allow_unverified_demo_numbers', false);

    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263771000555', 'Prod Review Farmer');
    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();

    $plan = FarmPlan::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $broilers->id,
        'title' => 'Prod Rule Broilers (test fixture, not agronomic data)',
        'state' => \App\Enums\PlanState::PLAN_DRAFTED,
        'scale_value' => 500,
        'scale_unit' => 'birds',
        'currency' => CurrencyCode::USD,
    ]);

    $engine = app(PlanFinancialEngine::class);
    $projection = $engine->runProjections($plan);
    $serialized = $engine->serializeForFarmer($plan->fresh(), $projection);

    expect($plan->fresh()->verdict_label)->toBe('under agronomist review')
        ->and($serialized['numbers_hidden_pending_review'])->toBeTrue()
        ->and($serialized['projection'])->toBeNull();
});

it('refuses to run PlanningTestFixturesSeeder in production', function (): void {
    app()->detectEnvironment(fn () => 'production');
    try {
        expect(fn () => (new \Database\Seeders\PlanningTestFixturesSeeder())->run())
            ->toThrow(\RuntimeException::class, 'PlanningTestFixturesSeeder cannot run in production.');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('idempotently backfills legacy consents and location_consents into consent_events', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263771000666', 'Backfill Farmer');

    \App\Models\Consent::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'status' => 'opted_in',
        'data_processing_consent' => true,
        'marketing_consent' => true,
        'policy_version' => 'v1.0-cdpa',
        'consented_at' => now()->subDays(5),
    ]);

    \App\Models\LocationConsent::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'gps_capture_consent' => true,
        'share_exact_with_outlet_consent' => false,
        'consented_at' => now()->subDays(4),
    ]);

    $action = app(\App\Actions\Consent\BackfillConsentEventsAction::class);
    $first = $action->execute();
    $second = $action->execute();

    expect($first['events_created'])->toBeGreaterThanOrEqual(3)
        ->and($second['events_created'])->toBe(0)
        ->and(ConsentEvent::hasActiveConsent($tenant->id, $farmer->id, 'data_processing'))->toBeTrue()
        ->and(ConsentEvent::hasActiveConsent($tenant->id, $farmer->id, 'marketing'))->toBeTrue()
        ->and(ConsentEvent::hasActiveConsent($tenant->id, $farmer->id, 'gps_capture'))->toBeTrue()
        ->and(ConsentEvent::hasActiveConsent($tenant->id, $farmer->id, 'share_exact_location'))->toBeFalse();
});
