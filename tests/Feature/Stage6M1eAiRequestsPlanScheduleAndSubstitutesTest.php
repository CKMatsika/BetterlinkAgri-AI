<?php

declare(strict_types=1);

use App\Enums\ConversationState;
use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\District;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\MarketplaceRequest;
use App\Models\Outlet;
use App\Models\OutletStock;
use App\Models\PlanEnterprise;
use App\Models\PlanRequirement;
use App\Models\Product;
use App\Models\Province;
use App\Models\Tenant;
use App\Services\Ai\AiAgentService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanningTestFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(PlanningTestFixturesSeeder::class);
});

it('executes create_marketplace_request AI tool from diagnosis and activates scheduled plan_requirements into linked marketplace requests', function (): void {
    Queue::fake();

    $tenant = Tenant::query()->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263776100100', 'M1e Farmer');

    $conv = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::ADVISORY_DELIVERED,
        'language' => 'en',
        'last_message_at' => now(),
    ]);

    // 1. AI Tool #14: create_marketplace_request executed via AiAgentService
    /** @var AiAgentService $agent */
    $agent = app(AiAgentService::class);
    $toolRes = $agent->executeTool($conv, 'create_marketplace_request', [
        'item_name' => 'Emamectin Benzoate 100ml',
        'quantity_scaled' => 200,
        'unit' => 'bottles',
        'latitude' => -17.8292,
        'longitude' => 31.0522,
    ]);

    $aiReq = MarketplaceRequest::withoutGlobalScopes()->findOrFail($toolRes['request_id']);
    expect($aiReq->source)->toBe('ai_conversation')
        ->and($aiReq->conversation_id)->toBe($conv->id)
        ->and($aiReq->status)->toBe(MarketplaceRequestStatus::BROADCAST_RING_1)
        ->and(AuditLog::query()->where('conversation_id', '=', $conv->id)->where('event_type', '=', 'tool_execution')->exists())->toBeTrue();

    // 2. Plan schedule activation via API (links plan_requirements.marketplace_request_id FK)
    $otp = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263776100100'])->assertStatus(200)->json('debug_otp');
    $token = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263776100100', 'otp' => $otp])->assertStatus(200)->json('token');

    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();
    $plan = FarmPlan::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $broilers->id,
        'title' => 'M1e Plan Schedule (test fixture, not agronomic data)',
        'scale_value' => 500,
        'scale_unit' => 'birds',
    ]);

    $planReq = PlanRequirement::query()->create([
        'farm_plan_id' => $plan->id,
        'requirement_type' => 'feed',
        'item_code' => 'broiler_starter_mash',
        'item_name' => 'Broiler Starter Mash 50kg',
        'quantity_scaled' => 1000,
        'unit' => 'bags',
        'needed_by_date' => now()->addDays(7)->toDateString(),
        'activation_at' => now(),
        'status' => 'scheduled',
    ]);

    $actRes = $this->withToken($token)->postJson("/api/v1/marketplace/plan-requirements/{$planReq->id}/activate", [
        'approx_latitude' => -17.8292,
        'approx_longitude' => 31.0522,
    ])->assertStatus(201);

    $linkedReqId = (string) $actRes->json('request.id');
    expect($planReq->fresh()->status)->toBe('activated')
        ->and($planReq->fresh()->marketplace_request_id)->toBe($linkedReqId)
        ->and($actRes->json('request.source'))->toBe('plan_schedule');
});

it('resolves safe registered in-stock substitutes, blocks expired/restricted products, and requires farmer confirmation with IDOR protection', function (): void {
    Queue::fake();
    Outlet::withoutGlobalScopes()->delete();

    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();
    $org = Tenant::query()->create(['name' => 'Sub Agro Shop', 'slug' => 'sub-agro', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);

    $outletId = (string) Str::uuid();
    if (DB::getDriverName() === 'pgsql') {
        DB::insert(
            "INSERT INTO outlets (id, tenant_id, outlet_type, name, address, province_id, district_id, latitude, longitude, phone, is_active, location_point, created_at, updated_at)
             VALUES (?, ?, 'retail_branch', 'Sub Outlet', '5 Sub St', ?, ?, -17.8100, 31.0522, '+263776000001', true, ST_SetSRID(ST_MakePoint(31.0522, -17.8100), 4326)::geography, NOW(), NOW())",
            [$outletId, $org->id, $province->id, $district->id]
        );
    } else {
        $outlet = Outlet::withoutGlobalScopes()->create([
            'tenant_id' => $org->id, 'outlet_type' => 'retail_branch', 'name' => 'Sub Outlet',
            'address' => '5 Sub St', 'province_id' => $province->id, 'district_id' => $district->id,
            'latitude' => -17.8100, 'longitude' => 31.0522, 'phone' => '+263776000001', 'is_active' => true,
        ]);
        $outletId = $outlet->id;
    }

    $original = Product::withoutGlobalScopes()->create([
        'tenant_id' => $org->id, 'sku' => 'ORIG-EM-1', 'name' => 'Original Emamectin', 'category' => 'insecticide',
        'active_ingredient' => 'Emamectin Benzoate', 'formulation' => 'EC', 'is_active' => true, 'is_restricted_use' => false,
    ]);
    $safeSub = Product::withoutGlobalScopes()->create([
        'tenant_id' => $org->id, 'sku' => 'SAFE-EM-2', 'name' => 'Safe Alt Emamectin', 'category' => 'insecticide',
        'active_ingredient' => 'Emamectin Benzoate', 'formulation' => 'SG', 'is_active' => true, 'is_restricted_use' => false,
        'registration_expiry' => now()->addYear()->toDateString(),
    ]);
    $expiredSub = Product::withoutGlobalScopes()->create([
        'tenant_id' => $org->id, 'sku' => 'EXP-EM-3', 'name' => 'Expired Emamectin', 'category' => 'insecticide',
        'active_ingredient' => 'Emamectin Benzoate', 'formulation' => 'EC', 'is_active' => true, 'is_restricted_use' => false,
        'registration_expiry' => now()->subDay()->toDateString(),
    ]);
    $restrictedSub = Product::withoutGlobalScopes()->create([
        'tenant_id' => $org->id, 'sku' => 'RES-EM-4', 'name' => 'Restricted Emamectin', 'category' => 'insecticide',
        'active_ingredient' => 'Emamectin Benzoate', 'formulation' => 'EC', 'is_active' => true, 'is_restricted_use' => true,
    ]);

    foreach ([$safeSub, $expiredSub, $restrictedSub] as $p) {
        OutletStock::withoutGlobalScopes()->create([
            'tenant_id' => $org->id, 'outlet_id' => $outletId, 'product_id' => $p->id,
            'pack_size' => '100ml', 'quantity_band' => 'in_stock', 'price' => 14.50, 'currency' => 'USD', 'last_updated_at' => now(),
        ]);
    }

    $otpA = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263776200100'])->assertStatus(200)->json('debug_otp');
    $tokenA = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263776200100', 'otp' => $otpA])->assertStatus(200)->json('token');

    $otpB = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263776200200'])->assertStatus(200)->json('debug_otp');
    $tokenB = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263776200200', 'otp' => $otpB])->assertStatus(200)->json('token');

    $reqId = (string) $this->withToken($tokenA)->postJson('/api/v1/marketplace/requests', [
        'approx_latitude' => -17.8292,
        'approx_longitude' => 31.0522,
        'items' => [['product_id' => $original->id, 'item_name' => $original->name, 'quantity_scaled' => 100, 'unit' => 'bottles']],
    ])->assertStatus(201)->json('request.id');

    // Only $safeSub is returned (expired and restricted are blocked by safety guardrails)
    $subRes = $this->withToken($tokenA)->getJson("/api/v1/marketplace/requests/{$reqId}/substitutes")->assertStatus(200);
    expect(count($subRes->json('substitutes')))->toBe(1)
        ->and($subRes->json('substitutes.0.product_id'))->toBe($safeSub->id)
        ->and($subRes->json('substitutes.0.requires_farmer_confirmation'))->toBeTrue();

    // Farmer B cannot confirm substitute on Farmer A's request (IDOR -> 403)
    $this->withToken($tokenB)->postJson("/api/v1/marketplace/requests/{$reqId}/substitutes/confirm", [
        'substitute_product_id' => $safeSub->id,
        'quantity_scaled' => 100,
        'unit' => 'bottles',
    ])->assertStatus(403);

    // Farmer A explicitly confirms safe substitute -> spawns new Ring-1 request
    $confirmRes = $this->withToken($tokenA)->postJson("/api/v1/marketplace/requests/{$reqId}/substitutes/confirm", [
        'substitute_product_id' => $safeSub->id,
        'quantity_scaled' => 100,
        'unit' => 'bottles',
    ])->assertStatus(201);

    expect($confirmRes->json('request.metadata_json.farmer_confirmed_substitute'))->toBeTrue()
        ->and($confirmRes->json('request.items.0.product_id'))->toBe($safeSub->id);
});
