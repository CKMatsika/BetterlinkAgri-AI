<?php

declare(strict_types=1);

use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Jobs\Marketplace\ExpandMarketplaceRingJob;
use App\Jobs\Marketplace\ExpireMarketplaceRequestJob;
use App\Models\District;
use App\Models\MarketplaceNotification;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\OrderEvent;
use App\Models\Outlet;
use App\Models\Province;
use App\Models\RequestRecipient;
use App\Models\StockSignal;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MarketplaceBroadcastService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

function createSpatialOutlet(
    Tenant $org,
    Province $province,
    District $district,
    string $name,
    float $lat,
    float $lng
): Outlet {
    $id = (string) Str::uuid();
    if (DB::getDriverName() === 'pgsql') {
        DB::insert(
            "INSERT INTO outlets (id, tenant_id, outlet_type, name, address, province_id, district_id, latitude, longitude, phone, is_active, location_point, created_at, updated_at)
             VALUES (?, ?, 'retail_branch', ?, 'Test Addr', ?, ?, ?, ?, '+263770000000', true, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, NOW(), NOW())",
            [$id, $org->id, $name, $province->id, $district->id, $lat, $lng, $lng, $lat]
        );

        return Outlet::withoutGlobalScopes()->findOrFail($id);
    }

    return Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $org->id,
        'outlet_type' => 'retail_branch',
        'name' => $name,
        'address' => 'Test Addr',
        'province_id' => $province->id,
        'district_id' => $district->id,
        'latitude' => $lat,
        'longitude' => $lng,
        'phone' => '+263770000000',
        'is_active' => true,
    ]);
}

function loginMarketplaceFarmer(mixed $test, string $phone): string
{
    $otpRes = $test->postJson('/api/v1/planner/auth/request-otp', ['phone' => $phone])->assertStatus(200);
    $ver = $test->postJson('/api/v1/planner/auth/verify-otp', [
        'phone' => $phone,
        'otp' => $otpRes->json('debug_otp'),
    ])->assertStatus(200);

    return (string) $ver->json('token');
}

it('broadcasts to ring 1, tracks opened_at and live counters, expands rings via delayed Horizon jobs without re-notifying, and escalates upstream when exhausted', function (): void {
    Queue::fake();

    // Remove seeded outlets for clean ring geometry around Harare (-17.8292, 31.0522)
    Outlet::withoutGlobalScopes()->delete();

    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $org1 = Tenant::query()->create(['name' => 'Ring 1 Shop', 'slug' => 'ring1-shop', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $org2 = Tenant::query()->create(['name' => 'Ring 2 Shop', 'slug' => 'ring2-shop', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::INDEPENDENT_RETAILER]);
    $org3 = Tenant::query()->create(['name' => 'Ring 3 Shop', 'slug' => 'ring3-shop', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);

    $outlet1 = createSpatialOutlet($org1, $province, $district, 'Ring 1 (~5km)', -17.7842, 31.0522);
    $outlet2 = createSpatialOutlet($org2, $province, $district, 'Ring 2 (~18km)', -17.6670, 31.0522);
    $outlet3 = createSpatialOutlet($org3, $province, $district, 'Ring 3 (~35km)', -17.5150, 31.0522);

    $user1 = User::query()->create(['tenant_id' => $org1->id, 'name' => 'Mgr 1', 'email' => 'm1@shop.zw', 'password' => bcrypt('secret'), 'role' => 'outlet_manager']);
    $farmerToken = loginMarketplaceFarmer($this, '+263773111000');

    // 1. Farmer posts request -> broadcasts to Ring 1 (10 km) and schedules delayed Horizon timer
    $createRes = $this->withToken($farmerToken)->postJson('/api/v1/marketplace/requests', [
        'approx_latitude' => -17.8292,
        'approx_longitude' => 31.0522,
        'exact_location' => 'Plot 12 Borrowdale',
        'delivery_preference' => 'pickup',
        'currency' => 'USD',
        'items' => [
            ['category' => 'fertiliser', 'item_name' => 'Compound D 50kg', 'quantity_scaled' => 400, 'unit' => 'bags'],
        ],
    ])->assertStatus(201);

    $requestId = (string) $createRes->json('request.id');
    expect($createRes->json('ring_1_notified_count'))->toBe(1)
        ->and($createRes->json('counters.sent'))->toBe(1)
        ->and($createRes->json('counters.opened'))->toBe(0);

    Queue::assertPushedOn('marketplace-timers', ExpandMarketplaceRingJob::class, fn ($job) => $job->requestId === $requestId && $job->ringNumber === 1 && $job->delay !== null);
    Queue::assertPushedOn('marketplace-timers', ExpireMarketplaceRequestJob::class, fn ($job) => $job->requestId === $requestId);

    // 2. Retailer 1 opens alert -> opened_at recorded and live counter increments
    $recipient1 = RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->where('outlet_id', '=', $outlet1->id)->firstOrFail();
    $this->actingAs($user1, 'sanctum')
        ->postJson("/api/v1/marketplace/recipients/{$recipient1->id}/opened")
        ->assertStatus(200)
        ->assertJsonPath('counters.opened', 1);

    // Retailer 1 responds out_of_stock -> creates StockSignal
    $this->actingAs($user1, 'sanctum')
        ->postJson("/api/v1/marketplace/recipients/{$recipient1->id}/respond", ['action' => 'out_of_stock'])
        ->assertStatus(200);
    expect(StockSignal::withoutGlobalScopes()->where('request_id', '=', $requestId)->where('signal_type', '=', 'out_of_stock_response')->count())->toBe(1);

    // 3. Ring 1 timer fires with 0 offers -> expands to Ring 2 (25 km), notifies outlet2 only (never re-notifies outlet1)
    /** @var MarketplaceBroadcastService $broadcast */
    $broadcast = app(MarketplaceBroadcastService::class);
    $broadcast->handleRingTimeout(MarketplaceRequest::withoutGlobalScopes()->findOrFail($requestId), 1);

    $reqAfterR2 = MarketplaceRequest::withoutGlobalScopes()->findOrFail($requestId);
    expect($reqAfterR2->status)->toBe(MarketplaceRequestStatus::BROADCAST_RING_2)
        ->and($reqAfterR2->current_ring)->toBe(2)
        ->and(RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->count())->toBe(2)
        ->and(MarketplaceNotification::query()->where('event_type', '=', 'marketplace_ring_expanded')->where('body', 'like', '%25 km%')->exists())->toBeTrue();

    // 4. Ring 2 timer fires -> expands to Ring 3 (50 km)
    $broadcast->handleRingTimeout($reqAfterR2, 2);
    $reqAfterR3 = MarketplaceRequest::withoutGlobalScopes()->findOrFail($requestId);
    expect($reqAfterR3->status)->toBe(MarketplaceRequestStatus::BROADCAST_RING_3)
        ->and(RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->count())->toBe(3);

    // 5. Ring 3 timer fires with 0 offers -> UPSTREAM_ESCALATED + ring_exhausted StockSignal
    $broadcast->handleRingTimeout($reqAfterR3, 3);
    $reqEscalated = MarketplaceRequest::withoutGlobalScopes()->findOrFail($requestId);
    expect($reqEscalated->status)->toBe(MarketplaceRequestStatus::UPSTREAM_ESCALATED)
        ->and(StockSignal::withoutGlobalScopes()->where('request_id', '=', $requestId)->where('signal_type', '=', 'ring_exhausted')->count())->toBe(1);
});

it('ranks multiple offers deterministically, accepts one offer to create an Order + OrderEvent, closes competing offers, and reveals contact only with consent', function (): void {
    Queue::fake();
    Outlet::withoutGlobalScopes()->delete();

    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $orgExpensive = Tenant::query()->create(['name' => 'Expensive Shop', 'slug' => 'exp-shop', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $orgCheaper = Tenant::query()->create(['name' => 'Cheaper Shop', 'slug' => 'cheap-shop', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::INDEPENDENT_RETAILER]);

    $outletExp = createSpatialOutlet($orgExpensive, $province, $district, 'Expensive Ring 1', -17.8100, 31.0522);
    $outletCheap = createSpatialOutlet($orgCheaper, $province, $district, 'Cheaper Ring 1', -17.8100, 31.0522);

    $userExp = User::query()->create(['tenant_id' => $orgExpensive->id, 'name' => 'Exp Mgr', 'email' => 'exp@shop.zw', 'password' => bcrypt('secret'), 'role' => 'outlet_manager']);
    $userCheap = User::query()->create(['tenant_id' => $orgCheaper->id, 'name' => 'Cheap Mgr', 'email' => 'cheap@shop.zw', 'password' => bcrypt('secret'), 'role' => 'outlet_manager']);

    $farmerToken = loginMarketplaceFarmer($this, '+263773222000');

    $createRes = $this->withToken($farmerToken)->postJson('/api/v1/marketplace/requests', [
        'approx_latitude' => -17.8292,
        'approx_longitude' => 31.0522,
        'exact_location' => 'Farm 99 Mazowe',
        'delivery_preference' => 'pickup',
        'currency' => 'USD',
        'items' => [
            ['category' => 'fertiliser', 'item_name' => 'AN Top Dressing 50kg', 'quantity_scaled' => 200, 'unit' => 'bags'],
        ],
    ])->assertStatus(201);

    $requestId = (string) $createRes->json('request.id');
    $recExp = RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->where('outlet_id', '=', $outletExp->id)->firstOrFail();
    $recCheap = RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->where('outlet_id', '=', $outletCheap->id)->firstOrFail();

    // Submit 2 offers
    $offerExpId = (string) $this->actingAs($userExp, 'sanctum')->postJson("/api/v1/marketplace/recipients/{$recExp->id}/respond", [
        'action' => 'offer',
        'unit_price_minor' => 4200,
        'total_price_minor' => 8400,
        'quantity_available_scaled' => 200,
        'fulfilment_type' => 'pickup',
    ])->assertStatus(201)->json('offer.id');

    $offerCheapId = (string) $this->actingAs($userCheap, 'sanctum')->postJson("/api/v1/marketplace/recipients/{$recCheap->id}/respond", [
        'action' => 'offer',
        'unit_price_minor' => 3500,
        'total_price_minor' => 7000,
        'quantity_available_scaled' => 200,
        'fulfilment_type' => 'pickup',
    ])->assertStatus(201)->json('offer.id');

    // Farmer sees deterministically ranked offers (cheaper offer #1 with explanation)
    $showRes = $this->withToken($farmerToken)->getJson("/api/v1/marketplace/requests/{$requestId}")->assertStatus(200);
    expect($showRes->json('offers.0.id'))->toBe($offerCheapId)
        ->and($showRes->json('offers.0.rank_score_scaled'))->toBeGreaterThan($showRes->json('offers.1.rank_score_scaled'))
        ->and($showRes->json('offers.0.rank_explanation_json.summary'))->toContain('Ring 1');

    // Farmer accepts cheaper offer WITH contact-reveal consent
    $acceptRes = $this->withToken($farmerToken)->postJson("/api/v1/marketplace/requests/{$requestId}/offers/{$offerCheapId}/accept", [
        'grant_contact_reveal_consent' => true,
    ])->assertStatus(200);

    $orderId = (string) $acceptRes->json('order.id');
    expect($acceptRes->json('request_status'))->toBe(MarketplaceRequestStatus::OFFER_ACCEPTED->value)
        ->and(Offer::withoutGlobalScopes()->findOrFail($offerCheapId)->status)->toBe('accepted')
        ->and(Offer::withoutGlobalScopes()->findOrFail($offerExpId)->status)->toBe('closed')
        ->and(MarketplaceNotification::query()->where('event_type', '=', 'offer_closed')->where('organisation_id', '=', $orgExpensive->id)->exists())->toBeTrue()
        ->and(OrderEvent::query()->where('order_id', '=', $orderId)->where('event_type', '=', 'order_created')->exists())->toBeTrue()
        ->and(MarketplaceOrder::withoutGlobalScopes()->findOrFail($orderId)->farmer_contact_revealed_at)->not->toBeNull();

    // Winning retailer gets exact contact; losing retailer gets null
    $req = MarketplaceRequest::withoutGlobalScopes()->findOrFail($requestId);
    expect($req->revealFarmerContactFor($orgCheaper->id)['exact_location'])->toBe('Farm 99 Mazowe')
        ->and($req->revealFarmerContactFor($orgExpensive->id))->toBeNull();
});
