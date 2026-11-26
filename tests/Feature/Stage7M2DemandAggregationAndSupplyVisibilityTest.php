<?php

declare(strict_types=1);

use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Models\District;
use App\Models\Farmer;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\Province;
use App\Models\RequestItem;
use App\Models\SaleEvent;
use App\Models\StockSignal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\DemandAggregationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('completes orders into anonymised sale_events, aggregates demand, and enforces the M2 manufacturer/distributor/agritex/bank visibility matrix', function (): void {
    expect(Schema::hasColumn('sale_events', 'farmer_id'))->toBeFalse();

    $province = Province::query()->firstOrFail();
    $districts = District::query()->where('province_id', '=', $province->id)->take(2)->get();
    $districtHigh = $districts->get(0);
    $districtLow = $districts->get(1) ?? District::query()->where('id', '!=', $districtHigh->id)->firstOrFail();

    $retailerA = Tenant::query()->create(['name' => 'Retailer A', 'slug' => 'm2-retailer-a', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $retailerB = Tenant::query()->create(['name' => 'Retailer B', 'slug' => 'm2-retailer-b', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $mfgA = Tenant::query()->create(['name' => 'Brand Alpha Mfg', 'slug' => 'm2-mfg-a', 'tenant_type' => 'manufacturer', 'organisation_type' => OrganisationType::MANUFACTURER]);
    $mfgB = Tenant::query()->create(['name' => 'Brand Beta Mfg', 'slug' => 'm2-mfg-b', 'tenant_type' => 'manufacturer', 'organisation_type' => OrganisationType::MANUFACTURER]);
    $distributor = Tenant::query()->create(['name' => 'National Wholesaler', 'slug' => 'm2-dist', 'tenant_type' => 'distributor', 'organisation_type' => OrganisationType::DISTRIBUTOR]);
    $agritex = Tenant::query()->create(['name' => 'Agritex Office', 'slug' => 'm2-agritex', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::GOVERNMENT_OFFICE]);
    $bank = Tenant::query()->create(['name' => 'AgriBank', 'slug' => 'm2-bank', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::BANK]);

    $makeUser = static fn (Tenant $t, string $email): User => User::withoutGlobalScopes()->create([
        'tenant_id' => $t->id,
        'name' => $t->name . ' User',
        'email' => $email,
        'password' => bcrypt('secret1234'),
        'role' => 'tenant_admin',
    ]);

    $userRetA = $makeUser($retailerA, 'm2-reta@example.test');
    $userRetB = $makeUser($retailerB, 'm2-retb@example.test');
    $userMfgA = $makeUser($mfgA, 'm2-mfga@example.test');
    $userDist = $makeUser($distributor, 'm2-dist@example.test');
    $userAgritex = $makeUser($agritex, 'm2-agritex@example.test');
    $userBank = $makeUser($bank, 'm2-bank@example.test');

    $outletA = Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $retailerA->id, 'outlet_type' => 'retail_branch', 'name' => 'Retailer A Branch',
        'address' => '1 Main Rd', 'province_id' => $province->id, 'district_id' => $districtHigh->id,
        'latitude' => -17.82, 'longitude' => 31.05, 'phone' => '+263777100001', 'is_active' => true,
    ]);

    $prodMfgA = Product::withoutGlobalScopes()->create([
        'tenant_id' => $mfgA->id, 'manufacturer_tenant_id' => $mfgA->id,
        'sku' => 'MFGA-01', 'name' => 'Alpha Emamectin 100ml', 'category' => 'insecticide',
        'active_ingredient' => 'Emamectin Benzoate', 'is_active' => true, 'is_restricted_use' => false,
    ]);
    $prodMfgB = Product::withoutGlobalScopes()->create([
        'tenant_id' => $mfgB->id, 'manufacturer_tenant_id' => $mfgB->id,
        'sku' => 'MFGB-01', 'name' => 'Beta Lambda 100ml', 'category' => 'insecticide',
        'active_ingredient' => 'Lambda-Cyhalothrin', 'is_active' => true, 'is_restricted_use' => false,
    ]);

    // Create 5 distinct farmers in $districtHigh requesting $prodMfgA (meets Agritex k >= 5)
    $firstRequest = null;
    $firstFarmer = null;
    for ($i = 1; $i <= 5; $i++) {
        $farmer = Farmer::findOrCreateByPhone('+26377810000' . $i, 'High District Farmer ' . $i);
        $req = MarketplaceRequest::withoutGlobalScopes()->create([
            'farmer_id' => $farmer->id,
            'request_type' => 'stock',
            'status' => $i === 1 ? MarketplaceRequestStatus::OFFER_ACCEPTED : MarketplaceRequestStatus::UPSTREAM_ESCALATED,
            'source' => 'web',
            'province_id' => $province->id,
            'district_id' => $districtHigh->id,
            'currency' => 'USD',
            'current_ring' => 1,
        ]);
        RequestItem::query()->create([
            'request_id' => $req->id,
            'product_id' => $prodMfgA->id,
            'category' => 'insecticide',
            'item_name' => $prodMfgA->name,
            'quantity_scaled' => 200,
            'unit' => 'bottles',
        ]);
        if ($i === 1) {
            $firstRequest = $req;
            $firstFarmer = $farmer;
        }
    }

    // Create only 4 distinct farmers in $districtLow requesting $prodMfgB (below Agritex k >= 5 -> MUST be suppressed)
    for ($i = 1; $i <= 4; $i++) {
        $farmer = Farmer::findOrCreateByPhone('+26377820000' . $i, 'Low District Farmer ' . $i);
        $req = MarketplaceRequest::withoutGlobalScopes()->create([
            'farmer_id' => $farmer->id,
            'request_type' => 'stock',
            'status' => MarketplaceRequestStatus::EXPIRED,
            'source' => 'web',
            'province_id' => $province->id,
            'district_id' => $districtLow->id,
            'currency' => 'USD',
            'current_ring' => 3,
        ]);
        RequestItem::query()->create([
            'request_id' => $req->id,
            'product_id' => $prodMfgB->id,
            'category' => 'insecticide',
            'item_name' => $prodMfgB->name,
            'quantity_scaled' => 100,
            'unit' => 'bottles',
        ]);
    }

    // Create an accepted order on $firstRequest and complete it via Retailer A endpoint
    $offer = Offer::withoutGlobalScopes()->create([
        'request_id' => $firstRequest->id,
        'organisation_id' => $retailerA->id,
        'outlet_id' => $outletA->id,
        'status' => 'accepted',
        'unit_price_minor' => 1200,
        'total_price_minor' => 2400,
        'currency' => 'USD',
        'quantity_available_scaled' => 200,
        'fulfilment_type' => 'pickup',
        'delivery_fee_minor' => 0,
        'valid_until' => now()->addHours(12),
    ]);
    $order = MarketplaceOrder::withoutGlobalScopes()->create([
        'request_id' => $firstRequest->id,
        'offer_id' => $offer->id,
        'farmer_id' => $firstFarmer->id,
        'organisation_id' => $retailerA->id,
        'outlet_id' => $outletA->id,
        'status' => 'created',
        'total_amount_minor' => 2400,
        'delivery_fee_minor' => 0,
        'currency' => 'USD',
        'fulfilment_type' => 'pickup',
        'payment_method' => 'cash_on_pickup',
        'payment_status' => 'unpaid',
    ]);

    // Retailer B cannot complete Retailer A's order (403)
    $this->actingAs($userRetB, 'sanctum')
        ->postJson("/api/v1/marketplace/orders/{$order->id}/complete")
        ->assertStatus(403);

    // Retailer A completes order -> emits anonymised SaleEvent
    $this->actingAs($userRetA, 'sanctum')
        ->postJson("/api/v1/marketplace/orders/{$order->id}/complete")
        ->assertStatus(200)
        ->assertJsonPath('order.status', 'completed');

    expect(SaleEvent::withoutGlobalScopes()->where('order_id', '=', $order->id)->count())->toBe(1)
        ->and($firstRequest->fresh()->status)->toBe(MarketplaceRequestStatus::COMPLETED);

    // Record stock signals for both Manufacturer A and Manufacturer B
    StockSignal::withoutGlobalScopes()->create([
        'organisation_id' => $retailerA->id, 'outlet_id' => $outletA->id, 'product_id' => $prodMfgA->id,
        'category' => 'insecticide', 'district_id' => $districtHigh->id,
        'signal_type' => 'out_of_stock_response', 'quantity_requested_scaled' => 400, 'occurred_at' => now(),
    ]);
    StockSignal::withoutGlobalScopes()->create([
        'organisation_id' => $retailerA->id, 'outlet_id' => $outletA->id, 'product_id' => $prodMfgB->id,
        'category' => 'insecticide', 'district_id' => $districtLow->id,
        'signal_type' => 'ring_exhausted', 'quantity_requested_scaled' => 300, 'occurred_at' => now(),
    ]);

    // Run deterministic demand aggregation
    $summary = app(DemandAggregationService::class)->aggregatePeriod(now()->subDay(), now(), 'weekly');
    expect($summary['area_rows'])->toBeGreaterThanOrEqual(2)
        ->and($summary['manufacturer_rows'])->toBeGreaterThanOrEqual(2)
        ->and($summary['retailer_rows'])->toBeGreaterThanOrEqual(1);

    // 1. Agritex (government_office): sees ONLY k >= 5 cell ($districtHigh / $prodMfgA), suppressed < 5 ($districtLow / $prodMfgB)
    $agritexRes = $this->actingAs($userAgritex, 'sanctum')->getJson('/api/v1/marketplace/intelligence/demand')->assertStatus(200);
    $agritexProductIds = collect($agritexRes->json('aggregates'))->pluck('product_id')->all();
    expect($agritexProductIds)->toContain($prodMfgA->id)
        ->and($agritexProductIds)->not->toContain($prodMfgB->id);

    $agritexSigRes = $this->actingAs($userAgritex, 'sanctum')->getJson('/api/v1/marketplace/intelligence/stock-signals')->assertStatus(200);
    expect(collect($agritexSigRes->json('signals'))->pluck('product_id')->all())
        ->toContain($prodMfgA->id)
        ->not->toContain($prodMfgB->id);

    // 2. Manufacturer A: sees ONLY own-brand demand & stock signals, NEVER Manufacturer B, NEVER individual upstream requests
    $mfgRes = $this->actingAs($userMfgA, 'sanctum')->getJson('/api/v1/marketplace/intelligence/demand')->assertStatus(200);
    $mfgProductIds = collect($mfgRes->json('aggregates'))->pluck('product_id')->all();
    expect($mfgProductIds)->toContain($prodMfgA->id)
        ->and($mfgProductIds)->not->toContain($prodMfgB->id)
        ->and($mfgRes->json('aggregates.0.total_sales_minor'))->toBe(2400);

    $mfgSigRes = $this->actingAs($userMfgA, 'sanctum')->getJson('/api/v1/marketplace/intelligence/stock-signals')->assertStatus(200);
    expect(collect($mfgSigRes->json('signals'))->pluck('product_id')->all())
        ->toBe([$prodMfgA->id]);

    $this->actingAs($userMfgA, 'sanctum')->getJson('/api/v1/marketplace/intelligence/upstream-requests')->assertStatus(403);

    // 3. Distributor / Wholesaler: sees UPSTREAM_ESCALATED requests (zero farmer PII) and unmet stock signals
    $distUpRes = $this->actingAs($userDist, 'sanctum')->getJson('/api/v1/marketplace/intelligence/upstream-requests')->assertStatus(200);
    expect(count($distUpRes->json('upstream_requests')))->toBe(4)
        ->and(array_key_exists('farmer_phone_encrypted', $distUpRes->json('upstream_requests.0')))->toBeFalse()
        ->and(array_key_exists('farmer_id', $distUpRes->json('upstream_requests.0')))->toBeFalse();

    $distSigRes = $this->actingAs($userDist, 'sanctum')->getJson('/api/v1/marketplace/intelligence/stock-signals')->assertStatus(200);
    expect(count($distSigRes->json('signals')))->toBe(2);

    // 4. Bank: 403 Forbidden on all demand / stock signal / upstream endpoints
    $this->actingAs($userBank, 'sanctum')->getJson('/api/v1/marketplace/intelligence/demand')->assertStatus(403);
    $this->actingAs($userBank, 'sanctum')->getJson('/api/v1/marketplace/intelligence/stock-signals')->assertStatus(403);
    $this->actingAs($userBank, 'sanctum')->getJson('/api/v1/marketplace/intelligence/upstream-requests')->assertStatus(403);
});
