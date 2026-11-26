<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Models\AuditLog;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\PlanEnterprise;
use App\Models\PlanRequirement;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanningTestFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(PlanningTestFixturesSeeder::class);
});

it('runs the full offtaker forward-quote loop with plan_requirements.forward_quote_id FK and consent-gated agri-finance with live revocation', function (): void {
    $offtakerA = Tenant::query()->create(['name' => 'GMB Offtaker A', 'slug' => 'm5-offtaker-a', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::OFFTAKER]);
    $offtakerB = Tenant::query()->create(['name' => 'Grain Buyer B', 'slug' => 'm5-offtaker-b', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::OFFTAKER]);
    $bankA = Tenant::query()->create(['name' => 'AFC Land Bank', 'slug' => 'm5-bank-a', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::BANK]);
    $bankB = Tenant::query()->create(['name' => 'Other Bank', 'slug' => 'm5-bank-b', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::BANK]);

    $makeUser = static fn (Tenant $t, string $email): User => User::withoutGlobalScopes()->create([
        'tenant_id' => $t->id, 'name' => $t->name . ' Officer', 'email' => $email,
        'password' => bcrypt('secret1234'), 'role' => 'tenant_admin',
    ]);

    $userOffA = $makeUser($offtakerA, 'm5-offa@example.test');
    $userOffB = $makeUser($offtakerB, 'm5-offb@example.test');
    $userBankA = $makeUser($bankA, 'm5-banka@example.test');
    $userBankB = $makeUser($bankB, 'm5-bankb@example.test');

    $otpA = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263774100100'])->assertStatus(200)->json('debug_otp');
    $tokenA = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263774100100', 'otp' => $otpA])->assertStatus(200)->json('token');
    $farmerA = Farmer::findOrCreateByPhone('+263774100100');

    $otpB = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263774100200'])->assertStatus(200)->json('debug_otp');
    $tokenB = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263774100200', 'otp' => $otpB])->assertStatus(200)->json('token');

    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();
    $plan = FarmPlan::query()->create([
        'tenant_id' => $offtakerA->id, 'farmer_id' => $farmerA->id, 'enterprise_id' => $broilers->id,
        'title' => 'M5 Offtake & Finance Plan (test fixture)', 'scale_value' => 10, 'scale_unit' => 'tonnes',
    ]);
    $planReq = PlanRequirement::query()->create([
        'farm_plan_id' => $plan->id, 'requirement_type' => 'offtake', 'item_code' => 'maize_grain_white',
        'item_name' => 'White Maize Grade A', 'quantity_scaled' => 1000, 'unit' => 'tonnes',
        'needed_by_date' => now()->addMonths(4)->toDateString(), 'activation_at' => now(), 'status' => 'scheduled',
    ]);

    // 1. Farmer A requests forward quote linked to plan_requirements.forward_quote_id
    $quoteId = (string) $this->withToken($tokenA)->postJson('/api/v1/marketplace/forward-quotes', [
        'offtaker_organisation_id' => $offtakerA->id,
        'commodity_code' => 'maize_grain_white',
        'commodity_name' => 'White Maize Grade A',
        'grade' => 'Grade A',
        'quantity_scaled' => 1000, // 10.00 tonnes
        'unit' => 'tonnes',
        'delivery_window_start' => now()->addMonths(3)->toDateString(),
        'delivery_window_end' => now()->addMonths(4)->toDateString(),
        'plan_requirement_id' => $planReq->id,
    ])->assertStatus(201)->json('forward_quote.id');

    expect($planReq->fresh()->forward_quote_id)->toBe($quoteId);

    // Offtaker B cannot price Offtaker A's quote (403)
    $this->actingAs($userOffB, 'sanctum')->postJson("/api/v1/marketplace/offtaker/forward-quotes/{$quoteId}/quote", [
        'quoted_unit_price_minor' => 38000,
    ])->assertStatus(403);

    // Offtaker A prices quote at $380.00/tonne -> $3,800.00 total (380000 minor)
    $this->actingAs($userOffA, 'sanctum')->postJson("/api/v1/marketplace/offtaker/forward-quotes/{$quoteId}/quote", [
        'quoted_unit_price_minor' => 38000,
        'valid_hours' => 72,
    ])->assertStatus(200)
        ->assertJsonPath('forward_quote.status', 'quoted')
        ->assertJsonPath('forward_quote.total_value_minor', 380000);

    // Farmer B cannot accept Farmer A's forward quote (403)
    $this->withToken($tokenB)->postJson("/api/v1/marketplace/forward-quotes/{$quoteId}/accept", [
        'grant_contact_consent' => true,
    ])->assertStatus(403);

    // Farmer A accepts forward quote WITH contact consent
    $this->withToken($tokenA)->postJson("/api/v1/marketplace/forward-quotes/{$quoteId}/accept", [
        'grant_contact_consent' => true,
    ])->assertStatus(200)
        ->assertJsonPath('forward_quote.status', 'accepted')
        ->assertJsonPath('forward_quote.farmer_contact_revealed', true);

    expect($planReq->fresh()->status)->toBe('quoted');

    $offList = $this->actingAs($userOffA, 'sanctum')->getJson('/api/v1/marketplace/offtaker/forward-quotes')->assertStatus(200);
    expect($offList->json('forward_quotes.0.farmer_phone'))->toBe('+263774100100');

    // 2. Agri-Finance: MUST fail closed without explicit bank_plan_sharing consent (422)
    $this->withToken($tokenA)->postJson('/api/v1/marketplace/finance/applications', [
        'bank_organisation_id' => $bankA->id,
        'farm_plan_id' => $plan->id,
        'forward_quote_id' => $quoteId,
        'requested_amount_minor' => 150000,
        'purpose' => 'input_finance',
        'grant_bank_sharing_consent' => false,
    ])->assertStatus(422);

    // Submit WITH explicit bank_plan_sharing consent -> succeeds
    $appId = (string) $this->withToken($tokenA)->postJson('/api/v1/marketplace/finance/applications', [
        'bank_organisation_id' => $bankA->id,
        'farm_plan_id' => $plan->id,
        'forward_quote_id' => $quoteId,
        'requested_amount_minor' => 150000,
        'purpose' => 'input_finance',
        'grant_bank_sharing_consent' => true,
    ])->assertStatus(201)
        ->assertJsonPath('application.plan_snapshot_json.forward_quote_value_minor', 380000)
        ->json('application.id');

    // Bank B cannot view or review Bank A's application (0 rows + 403)
    $this->actingAs($userBankB, 'sanctum')->getJson('/api/v1/marketplace/bank/applications')
        ->assertStatus(200)
        ->assertJsonCount(0, 'applications');

    $this->actingAs($userBankB, 'sanctum')->postJson("/api/v1/marketplace/bank/applications/{$appId}/review", [
        'decision' => 'approved',
    ])->assertStatus(403);

    // Bank A sees application and approves $1400.00
    $this->actingAs($userBankA, 'sanctum')->getJson('/api/v1/marketplace/bank/applications')
        ->assertStatus(200)
        ->assertJsonCount(1, 'applications');

    $this->actingAs($userBankA, 'sanctum')->postJson("/api/v1/marketplace/bank/applications/{$appId}/review", [
        'decision' => 'approved',
        'approved_amount_minor' => 140000,
        'notes' => 'Approved backed by GMB forward quote.',
    ])->assertStatus(200)
        ->assertJsonPath('application.status', 'approved')
        ->assertJsonPath('application.approved_amount_minor', 140000);

    expect(AuditLog::withoutGlobalScopes()->where('event_type', '=', 'finance_application_reviewed')->exists())->toBeTrue();

    // 3. CDPA Live Consent Revocation: Farmer A revokes bank_plan_sharing -> Bank A immediately loses visibility & review access
    $this->withToken($tokenA)->postJson("/api/v1/marketplace/finance/revoke-consent/{$bankA->id}")
        ->assertStatus(200)
        ->assertJsonPath('consent_event.action', 'revoked');

    $this->actingAs($userBankA, 'sanctum')->getJson('/api/v1/marketplace/bank/applications')
        ->assertStatus(200)
        ->assertJsonCount(0, 'applications');

    $this->actingAs($userBankA, 'sanctum')->postJson("/api/v1/marketplace/bank/applications/{$appId}/review", [
        'decision' => 'declined',
    ])->assertStatus(403);
});
