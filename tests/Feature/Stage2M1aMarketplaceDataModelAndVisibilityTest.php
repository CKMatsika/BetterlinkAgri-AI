<?php

declare(strict_types=1);

use App\Enums\CurrencyCode;
use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Models\ConsentEvent;
use App\Models\DemandAggregate;
use App\Models\DeviceToken;
use App\Models\District;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\MarketplaceNotification;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\NotificationPreference;
use App\Models\Offer;
use App\Models\OrderEvent;
use App\Models\Outlet;
use App\Models\PlanEnterprise;
use App\Models\PlanRequirement;
use App\Models\Province;
use App\Models\Rating;
use App\Models\RequestItem;
use App\Models\RequestRecipient;
use App\Models\SaleEvent;
use App\Models\SellerVerification;
use App\Models\StockSignal;
use App\Models\Tenant;
use App\Models\User;
use App\Policies\DemandAggregatePolicy;
use App\Policies\MarketplaceOrderPolicy;
use App\Policies\MarketplaceRequestPolicy;
use App\Policies\OfferPolicy;
use App\Support\MarketplaceContext;
use App\Support\TenantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanningTestFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(PlanningTestFixturesSeeder::class);
    app(MarketplaceContext::class)->reset();
    app(TenantContext::class)->setTenantId(null);
});

afterEach(function (): void {
    app(MarketplaceContext::class)->reset();
    app(TenantContext::class)->setTenantId(null);
});

it('creates all 14 M1a marketplace tables, organisation_type on tenants, and plan_requirements.marketplace_request_id FK', function (): void {
    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $indRetailer = Tenant::query()->create([
        'name' => 'Kudzanai Independent Agro Shop',
        'slug' => 'kudzanai-independent',
        'tenant_type' => 'agro_dealer',
        'organisation_type' => OrganisationType::INDEPENDENT_RETAILER,
        'is_active' => true,
    ]);

    $outlet = Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $indRetailer->id,
        'outlet_type' => 'agro_dealer',
        'name' => 'Kudzanai Main Branch',
        'address' => '12 Main St',
        'province_id' => $province->id,
        'district_id' => $district->id,
        'latitude' => -17.8292,
        'longitude' => 31.0522,
        'phone' => '+263771111222',
        'is_active' => true,
    ]);

    $farmer = Farmer::findOrCreateByPhone('+263771500100', 'M1a Farmer');
    $req = MarketplaceRequest::query()->create([
        'farmer_id' => $farmer->id,
        'request_type' => 'stock',
        'status' => MarketplaceRequestStatus::BROADCAST_RING_1,
        'source' => 'plan_schedule',
        'province_id' => $province->id,
        'district_id' => $district->id,
        'approx_latitude' => -17.8200,
        'approx_longitude' => 31.0500,
        'exact_location_encrypted' => '-17.8292,31.0522 Plot 42',
        'farmer_phone_encrypted' => '+263771500100',
        'currency' => CurrencyCode::USD,
        'current_ring' => 1,
    ]);

    RequestItem::query()->create([
        'request_id' => $req->id,
        'category' => 'fertiliser',
        'item_name' => 'Compound D 50kg',
        'quantity_scaled' => 400,
        'unit' => 'bags',
    ]);

    RequestRecipient::query()->create([
        'request_id' => $req->id,
        'organisation_id' => $indRetailer->id,
        'outlet_id' => $outlet->id,
        'ring_number' => 1,
        'distance_band' => '0-10km',
        'notified_at' => now(),
    ]);

    $offer = Offer::query()->create([
        'request_id' => $req->id,
        'organisation_id' => $indRetailer->id,
        'outlet_id' => $outlet->id,
        'status' => 'pending',
        'unit_price_minor' => 3400,
        'total_price_minor' => 13600,
        'currency' => CurrencyCode::USD,
        'quantity_available_scaled' => 400,
        'valid_until' => now()->addHours(12),
    ]);

    $order = MarketplaceOrder::query()->create([
        'request_id' => $req->id,
        'offer_id' => $offer->id,
        'farmer_id' => $farmer->id,
        'organisation_id' => $indRetailer->id,
        'outlet_id' => $outlet->id,
        'status' => 'created',
        'total_amount_minor' => 13600,
        'currency' => CurrencyCode::USD,
    ]);

    OrderEvent::query()->create([
        'order_id' => $order->id,
        'actor_type' => 'farmer',
        'actor_id' => $farmer->id,
        'event_type' => 'order_created',
        'to_status' => 'created',
        'created_at' => now(),
    ]);

    Rating::query()->create([
        'order_id' => $order->id,
        'rater_type' => 'farmer',
        'rater_id' => $farmer->id,
        'ratee_type' => 'retailer',
        'ratee_id' => $indRetailer->id,
        'score' => 5,
    ]);

    MarketplaceNotification::query()->create([
        'recipient_type' => 'outlet',
        'recipient_id' => $outlet->id,
        'organisation_id' => $indRetailer->id,
        'channel' => 'reverb',
        'event_type' => 'new_request',
        'body' => 'New stock request nearby',
    ]);

    NotificationPreference::query()->create([
        'owner_type' => 'farmer',
        'owner_id' => $farmer->id,
        'preferred_channels_json' => ['whatsapp', 'sms'],
    ]);

    DeviceToken::query()->create([
        'owner_type' => 'farmer',
        'owner_id' => $farmer->id,
        'platform' => 'android',
        'token_hash' => hash('sha256', 'dummy-fcm-token'),
        'token_encrypted' => 'dummy-fcm-token',
    ]);

    SellerVerification::query()->create([
        'organisation_id' => $indRetailer->id,
        'outlet_id' => $outlet->id,
        'status' => 'verified',
        'business_registration_number' => 'ZW-12345',
    ]);

    StockSignal::query()->create([
        'request_id' => $req->id,
        'organisation_id' => $indRetailer->id,
        'outlet_id' => $outlet->id,
        'district_id' => $district->id,
        'signal_type' => 'low_stock',
        'occurred_at' => now(),
    ]);

    SaleEvent::query()->create([
        'order_id' => $order->id,
        'organisation_id' => $indRetailer->id,
        'outlet_id' => $outlet->id,
        'district_id' => $district->id,
        'quantity_scaled' => 400,
        'total_amount_minor' => 13600,
        'currency' => CurrencyCode::USD,
        'occurred_at' => now(),
    ]);

    DemandAggregate::query()->create([
        'period_start' => now()->startOfWeek()->toDateString(),
        'period_end' => now()->endOfWeek()->toDateString(),
        'granularity' => 'weekly',
        'district_id' => $district->id,
        'organisation_id' => $indRetailer->id,
        'request_count' => 6,
        'contributing_farmers_count' => 6,
        'currency' => CurrencyCode::USD,
    ]);

    // Verify deferred FK on plan_requirements.marketplace_request_id -> requests.id
    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();
    $plan = FarmPlan::query()->create([
        'tenant_id' => $indRetailer->id,
        'farmer_id' => $farmer->id,
        'enterprise_id' => $broilers->id,
        'title' => 'Linked Plan (test fixture, not agronomic data)',
        'scale_value' => 500,
        'scale_unit' => 'birds',
    ]);

    $planReq = PlanRequirement::query()->create([
        'farm_plan_id' => $plan->id,
        'requirement_type' => 'input',
        'item_code' => 'compound_d',
        'item_name' => 'Compound D 50kg',
        'quantity_scaled' => 400,
        'unit' => 'bags',
        'needed_by_date' => now()->addDays(10)->toDateString(),
        'activation_at' => now(),
        'status' => 'activated',
        'marketplace_request_id' => $req->id,
    ]);

    expect($indRetailer->fresh()->organisation_type)->toBe(OrganisationType::INDEPENDENT_RETAILER)
        ->and($planReq->marketplace_request_id)->toBe($req->id);
});

it('enforces the MarketplaceScope and policy visibility matrix: competing offers, contact reveal, aggregate-only actors, cross-tenant, and farmer IDOR', function (): void {
    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $retailerA = Tenant::query()->create([
        'name' => 'Retailer A',
        'slug' => 'retailer-a',
        'tenant_type' => 'agro_dealer',
        'organisation_type' => OrganisationType::RETAILER,
    ]);
    $retailerB = Tenant::query()->create([
        'name' => 'Retailer B',
        'slug' => 'retailer-b',
        'tenant_type' => 'agro_dealer',
        'organisation_type' => OrganisationType::INDEPENDENT_RETAILER,
    ]);
    $manufacturer = Tenant::query()->create([
        'name' => 'SeedCo Manufacturer',
        'slug' => 'seedco-mfg',
        'tenant_type' => 'manufacturer',
        'organisation_type' => OrganisationType::MANUFACTURER,
    ]);
    $agritex = Tenant::query()->create([
        'name' => 'Agritex Mashonaland East',
        'slug' => 'agritex-mash-east',
        'tenant_type' => 'agro_dealer',
        'organisation_type' => OrganisationType::GOVERNMENT_OFFICE,
    ]);

    $userRetA = User::query()->create(['tenant_id' => $retailerA->id, 'name' => 'Ret A', 'email' => 'reta@test.zw', 'password' => bcrypt('secret'), 'role' => 'outlet_manager']);
    $userRetB = User::query()->create(['tenant_id' => $retailerB->id, 'name' => 'Ret B', 'email' => 'retb@test.zw', 'password' => bcrypt('secret'), 'role' => 'outlet_manager']);
    $userMfg = User::query()->create(['tenant_id' => $manufacturer->id, 'name' => 'Mfg', 'email' => 'mfg@test.zw', 'password' => bcrypt('secret'), 'role' => 'tenant_admin']);
    $userAgritex = User::query()->create(['tenant_id' => $agritex->id, 'name' => 'Agritex', 'email' => 'agritex@test.zw', 'password' => bcrypt('secret'), 'role' => 'agronomist']);

    $outletA = Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $retailerA->id, 'outlet_type' => 'retail_branch', 'name' => 'Outlet A',
        'address' => '1 A St', 'province_id' => $province->id, 'district_id' => $district->id,
        'latitude' => -17.82, 'longitude' => 31.05, 'phone' => '+263771000001', 'is_active' => true,
    ]);
    $outletB = Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $retailerB->id, 'outlet_type' => 'agro_dealer', 'name' => 'Outlet B',
        'address' => '2 B St', 'province_id' => $province->id, 'district_id' => $district->id,
        'latitude' => -17.83, 'longitude' => 31.06, 'phone' => '+263771000002', 'is_active' => true,
    ]);

    $farmerA = Farmer::findOrCreateByPhone('+263772111111', 'Farmer A');
    $farmerB = Farmer::findOrCreateByPhone('+263772222222', 'Farmer B');

    $reqA = MarketplaceRequest::query()->create([
        'farmer_id' => $farmerA->id,
        'status' => MarketplaceRequestStatus::OFFERS_RECEIVED,
        'district_id' => $district->id,
        'exact_location_encrypted' => 'Exact Farm A GPS -17.8292, 31.0522',
        'farmer_phone_encrypted' => '+263772111111',
        'currency' => CurrencyCode::USD,
        'current_ring' => 1,
    ]);
    $reqB = MarketplaceRequest::query()->create([
        'farmer_id' => $farmerB->id,
        'status' => MarketplaceRequestStatus::BROADCAST_RING_1,
        'district_id' => $district->id,
        'exact_location_encrypted' => 'Exact Farm B GPS',
        'farmer_phone_encrypted' => '+263772222222',
        'currency' => CurrencyCode::USD,
        'current_ring' => 1,
    ]);

    RequestRecipient::query()->create(['request_id' => $reqA->id, 'organisation_id' => $retailerA->id, 'outlet_id' => $outletA->id, 'ring_number' => 1, 'distance_band' => '0-10km', 'notified_at' => now()]);
    RequestRecipient::query()->create(['request_id' => $reqA->id, 'organisation_id' => $retailerB->id, 'outlet_id' => $outletB->id, 'ring_number' => 1, 'distance_band' => '0-10km', 'notified_at' => now()]);

    $offerA = Offer::query()->create([
        'request_id' => $reqA->id, 'organisation_id' => $retailerA->id, 'outlet_id' => $outletA->id,
        'status' => 'pending', 'unit_price_minor' => 3000, 'total_price_minor' => 3000,
        'currency' => CurrencyCode::USD, 'quantity_available_scaled' => 100, 'valid_until' => now()->addHours(12),
    ]);
    $offerB = Offer::query()->create([
        'request_id' => $reqA->id, 'organisation_id' => $retailerB->id, 'outlet_id' => $outletB->id,
        'status' => 'pending', 'unit_price_minor' => 2900, 'total_price_minor' => 2900,
        'currency' => CurrencyCode::USD, 'quantity_available_scaled' => 100, 'valid_until' => now()->addHours(12),
    ]);

    $aggSmall = DemandAggregate::query()->create([
        'period_start' => now()->startOfWeek()->toDateString(), 'period_end' => now()->endOfWeek()->toDateString(),
        'granularity' => 'weekly', 'district_id' => $district->id, 'organisation_id' => $retailerA->id,
        'request_count' => 3, 'contributing_farmers_count' => 3, 'currency' => CurrencyCode::USD,
    ]);
    $aggLarge = DemandAggregate::query()->create([
        'period_start' => now()->startOfWeek()->toDateString(), 'period_end' => now()->endOfWeek()->toDateString(),
        'granularity' => 'weekly', 'district_id' => $district->id, 'organisation_id' => null,
        'request_count' => 8, 'contributing_farmers_count' => 8, 'currency' => CurrencyCode::USD,
    ]);

    $reqPolicy = new MarketplaceRequestPolicy();
    $offerPolicy = new OfferPolicy();
    $orderPolicy = new MarketplaceOrderPolicy();
    $aggPolicy = new DemandAggregatePolicy();
    $ctx = app(MarketplaceContext::class);

    // 1. Retailers NEVER see other retailers' offers (scope + policy)
    $ctx->setOrganisationActor($retailerA->id, OrganisationType::RETAILER, $userRetA->id);
    expect(Offer::query()->pluck('id')->all())->toBe([$offerA->id])
        ->and($offerPolicy->view($userRetA, $offerA))->toBeTrue()
        ->and($offerPolicy->view($userRetA, $offerB))->toBeFalse();

    // 2. Exact farmer location & phone hidden before offer acceptance and consent
    expect($reqA->revealFarmerContactFor($retailerA->id))->toBeNull()
        ->and($reqPolicy->viewExactFarmerContact($userRetA, $reqA))->toBeFalse();

    // Order accepted for Retailer A, but NO consent yet -> STILL hidden
    $orderA = MarketplaceOrder::query()->create([
        'request_id' => $reqA->id, 'offer_id' => $offerA->id, 'farmer_id' => $farmerA->id,
        'organisation_id' => $retailerA->id, 'outlet_id' => $outletA->id,
        'status' => 'created', 'total_amount_minor' => 3000, 'currency' => CurrencyCode::USD,
    ]);
    expect($reqA->revealFarmerContactFor($retailerA->id))->toBeNull()
        ->and($reqPolicy->viewExactFarmerContact($userRetA, $reqA))->toBeFalse();

    // Consent granted -> revealed ONLY to Retailer A (never Retailer B)
    ConsentEvent::record($retailerA->id, $farmerA->id, 'marketplace_contact_reveal', 'granted', 'web', 'farmer_reply', '2026.1');
    expect($reqA->revealFarmerContactFor($retailerA->id))->toMatchArray([
        'exact_location' => 'Exact Farm A GPS -17.8292, 31.0522',
        'farmer_phone' => '+263772111111',
    ])
        ->and($reqPolicy->viewExactFarmerContact($userRetA, $reqA))->toBeTrue()
        ->and($reqA->revealFarmerContactFor($retailerB->id))->toBeNull()
        ->and($reqPolicy->viewExactFarmerContact($userRetB, $reqA))->toBeFalse();

    // 3. Manufacturers and Agritex NEVER see individual farmers' requests, offers, or orders
    $ctx->setOrganisationActor($manufacturer->id, OrganisationType::MANUFACTURER, $userMfg->id);
    expect(MarketplaceRequest::query()->count())->toBe(0)
        ->and(Offer::query()->count())->toBe(0)
        ->and(MarketplaceOrder::query()->count())->toBe(0)
        ->and($reqPolicy->view($userMfg, $reqA))->toBeFalse()
        ->and($offerPolicy->view($userMfg, $offerA))->toBeFalse()
        ->and($orderPolicy->view($userMfg, $orderA))->toBeFalse();

    $ctx->setOrganisationActor($agritex->id, OrganisationType::GOVERNMENT_OFFICE, $userAgritex->id, [], [$district->id]);
    expect(MarketplaceRequest::query()->count())->toBe(0)
        ->and(Offer::query()->count())->toBe(0)
        ->and(MarketplaceOrder::query()->count())->toBe(0)
        ->and($reqPolicy->view($userAgritex, $reqA))->toBeFalse()
        // Agritex sees only k >= 5 suppressed aggregates
        ->and(DemandAggregate::query()->pluck('id')->all())->toBe([$aggLarge->id])
        ->and($aggPolicy->view($userAgritex, $aggSmall))->toBeFalse()
        ->and($aggPolicy->view($userAgritex, $aggLarge))->toBeTrue();

    // 4. Cross-tenant access denied (Retailer B cannot see Retailer A's order or aggregate; TenantScope intact on Outlet)
    $ctx->setOrganisationActor($retailerB->id, OrganisationType::INDEPENDENT_RETAILER, $userRetB->id);
    expect(MarketplaceOrder::query()->count())->toBe(0)
        ->and($orderPolicy->view($userRetB, $orderA))->toBeFalse()
        ->and(DemandAggregate::query()->count())->toBe(0)
        ->and($aggPolicy->view($userRetB, $aggSmall))->toBeFalse();

    app(TenantContext::class)->setTenantId($retailerB->id);
    expect(Outlet::query()->pluck('id')->all())->toBe([$outletB->id]);
    app(TenantContext::class)->setTenantId(null);

    // 5. Farmer IDOR blocked: Farmer B cannot read Farmer A's request, offers, or orders
    $ctx->setFarmer($farmerB->id);
    expect(MarketplaceRequest::query()->pluck('id')->all())->toBe([$reqB->id])
        ->and(Offer::query()->count())->toBe(0)
        ->and(MarketplaceOrder::query()->count())->toBe(0)
        ->and($reqPolicy->view($farmerB, $reqA))->toBeFalse()
        ->and($offerPolicy->view($farmerB, $offerA))->toBeFalse()
        ->and($orderPolicy->view($farmerB, $orderA))->toBeFalse();
});
