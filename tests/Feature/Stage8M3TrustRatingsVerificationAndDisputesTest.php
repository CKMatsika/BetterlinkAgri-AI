<?php

declare(strict_types=1);

use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Farmer;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\OrderEvent;
use App\Models\Outlet;
use App\Models\Province;
use App\Models\RequestItem;
use App\Models\RequestRecipient;
use App\Models\SellerVerification;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MarketplaceBroadcastService;
use App\Services\Marketplace\OfferRankingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('supports mutual ratings on completed orders, prevents duplicates and IDOR, transitions to RATED, and updates deterministic offer ranking', function (): void {
    Queue::fake();

    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $retailerHigh = Tenant::query()->create(['name' => 'Trusted Agro', 'slug' => 'm3-trusted', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $retailerLow = Tenant::query()->create(['name' => 'Low Rated Agro', 'slug' => 'm3-low', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);

    $userHigh = User::withoutGlobalScopes()->create([
        'tenant_id' => $retailerHigh->id, 'name' => 'High User', 'email' => 'm3-high@example.test',
        'password' => bcrypt('secret1234'), 'role' => 'tenant_admin',
    ]);
    $userLow = User::withoutGlobalScopes()->create([
        'tenant_id' => $retailerLow->id, 'name' => 'Low User', 'email' => 'm3-low@example.test',
        'password' => bcrypt('secret1234'), 'role' => 'tenant_admin',
    ]);

    $outletHigh = Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $retailerHigh->id, 'outlet_type' => 'retail_branch', 'name' => 'Trusted Branch',
        'address' => '1 Trust St', 'province_id' => $province->id, 'district_id' => $district->id,
        'latitude' => -17.82, 'longitude' => 31.05, 'phone' => '+263779100001', 'is_active' => true,
    ]);
    $outletLow = Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $retailerLow->id, 'outlet_type' => 'retail_branch', 'name' => 'Low Branch',
        'address' => '2 Low St', 'province_id' => $province->id, 'district_id' => $district->id,
        'latitude' => -17.82, 'longitude' => 31.05, 'phone' => '+263779100002', 'is_active' => true,
    ]);

    $otpA = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263779200001'])->assertStatus(200)->json('debug_otp');
    $tokenA = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263779200001', 'otp' => $otpA])->assertStatus(200)->json('token');
    $farmerA = Farmer::findOrCreateByPhone('+263779200001');

    $otpB = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263779200002'])->assertStatus(200)->json('debug_otp');
    $tokenB = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263779200002', 'otp' => $otpB])->assertStatus(200)->json('token');

    $req1 = MarketplaceRequest::withoutGlobalScopes()->create([
        'farmer_id' => $farmerA->id, 'request_type' => 'stock', 'status' => MarketplaceRequestStatus::COMPLETED,
        'source' => 'web', 'province_id' => $province->id, 'district_id' => $district->id, 'currency' => 'USD', 'current_ring' => 1,
    ]);
    $offer1 = Offer::withoutGlobalScopes()->create([
        'request_id' => $req1->id, 'organisation_id' => $retailerHigh->id, 'outlet_id' => $outletHigh->id,
        'status' => 'accepted', 'unit_price_minor' => 1000, 'total_price_minor' => 1000, 'currency' => 'USD',
        'quantity_available_scaled' => 100, 'fulfilment_type' => 'pickup', 'delivery_fee_minor' => 0, 'valid_until' => now()->addHours(12),
    ]);
    $order1 = MarketplaceOrder::withoutGlobalScopes()->create([
        'request_id' => $req1->id, 'offer_id' => $offer1->id, 'farmer_id' => $farmerA->id,
        'organisation_id' => $retailerHigh->id, 'outlet_id' => $outletHigh->id, 'status' => 'completed',
        'total_amount_minor' => 1000, 'delivery_fee_minor' => 0, 'currency' => 'USD',
        'fulfilment_type' => 'pickup', 'payment_method' => 'cash_on_pickup', 'payment_status' => 'paid', 'completed_at' => now(),
    ]);

    // IDOR: Farmer B cannot rate Farmer A's order (403)
    $this->withToken($tokenB)->postJson("/api/v1/marketplace/orders/{$order1->id}/rate", ['score' => 5])->assertStatus(403);

    // Farmer A rates Trusted Agro 5/5 -> transitions request COMPLETED -> RATED
    $this->withToken($tokenA)->postJson("/api/v1/marketplace/orders/{$order1->id}/rate", [
        'score' => 5,
        'comment' => 'Fast and genuine stock.',
    ])->assertStatus(201)->assertJsonPath('rating.score', 5);

    expect($req1->fresh()->status)->toBe(MarketplaceRequestStatus::RATED);

    // Duplicate farmer rating rejected (422)
    $this->withToken($tokenA)->postJson("/api/v1/marketplace/orders/{$order1->id}/rate", ['score' => 4])->assertStatus(422);

    // Wrong retailer cannot rate Farmer A (403)
    $this->actingAs($userLow, 'sanctum')->postJson("/api/v1/marketplace/orders/{$order1->id}/rate-farmer", ['score' => 5])->assertStatus(403);

    // Winning retailer rates Farmer A 5/5
    $this->actingAs($userHigh, 'sanctum')->postJson("/api/v1/marketplace/orders/{$order1->id}/rate-farmer", [
        'score' => 5,
        'comment' => 'Prompt pickup.',
    ])->assertStatus(201)->assertJsonPath('rating.rater_type', 'retailer');

    // Now verify deterministic OfferRankingService ranks 5-star retailer strictly above unrated retailer at identical price/ring
    $req2 = MarketplaceRequest::withoutGlobalScopes()->create([
        'farmer_id' => $farmerA->id, 'request_type' => 'stock', 'status' => MarketplaceRequestStatus::OFFERS_RECEIVED,
        'source' => 'web', 'province_id' => $province->id, 'district_id' => $district->id, 'currency' => 'USD', 'current_ring' => 1,
    ]);
    RequestRecipient::withoutGlobalScopes()->create([
        'request_id' => $req2->id, 'organisation_id' => $retailerHigh->id, 'outlet_id' => $outletHigh->id,
        'ring_number' => 1, 'distance_band' => '0-10km', 'notified_at' => now(),
    ]);
    RequestRecipient::withoutGlobalScopes()->create([
        'request_id' => $req2->id, 'organisation_id' => $retailerLow->id, 'outlet_id' => $outletLow->id,
        'ring_number' => 1, 'distance_band' => '0-10km', 'notified_at' => now(),
    ]);
    $oHigh = Offer::withoutGlobalScopes()->create([
        'request_id' => $req2->id, 'organisation_id' => $retailerHigh->id, 'outlet_id' => $outletHigh->id,
        'status' => 'pending', 'unit_price_minor' => 1500, 'total_price_minor' => 1500, 'currency' => 'USD',
        'quantity_available_scaled' => 100, 'fulfilment_type' => 'pickup', 'delivery_fee_minor' => 0, 'valid_until' => now()->addHours(12),
    ]);
    $oLow = Offer::withoutGlobalScopes()->create([
        'request_id' => $req2->id, 'organisation_id' => $retailerLow->id, 'outlet_id' => $outletLow->id,
        'status' => 'pending', 'unit_price_minor' => 1500, 'total_price_minor' => 1500, 'currency' => 'USD',
        'quantity_available_scaled' => 100, 'fulfilment_type' => 'pickup', 'delivery_fee_minor' => 0, 'valid_until' => now()->addHours(12),
    ]);

    $ranked = app(OfferRankingService::class)->rankForRequest($req2);
    expect($ranked[0]->id)->toBe($oHigh->id)
        ->and($ranked[0]->rank_score_scaled)->toBeGreaterThan($ranked[1]->rank_score_scaled);
});

it('handles seller verification, dispute escalation, admin resolution with seller suspension, and excludes suspended sellers from broadcasts', function (): void {
    Queue::fake();
    Outlet::withoutGlobalScopes()->delete();

    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $retailer = Tenant::query()->create(['name' => 'Disputed Agro', 'slug' => 'm3-disputed-agro', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $userRetailer = User::withoutGlobalScopes()->create([
        'tenant_id' => $retailer->id, 'name' => 'Retailer Admin', 'email' => 'm3-ret@example.test',
        'password' => bcrypt('secret1234'), 'role' => 'tenant_admin',
    ]);
    $platformAdmin = User::withoutGlobalScopes()->create([
        'tenant_id' => null, 'name' => 'Platform Admin', 'email' => 'm3-platform@example.test',
        'password' => bcrypt('secret1234'), 'role' => 'super_admin',
    ]);

    $outletId = (string) Str::uuid();
    if (DB::getDriverName() === 'pgsql') {
        DB::insert(
            "INSERT INTO outlets (id, tenant_id, outlet_type, name, address, province_id, district_id, latitude, longitude, phone, is_active, location_point, created_at, updated_at)
             VALUES (?, ?, 'retail_branch', 'Disputed Outlet', '9 Dispute Rd', ?, ?, -17.8292, 31.0522, '+263779300001', true, ST_SetSRID(ST_MakePoint(31.0522, -17.8292), 4326)::geography, NOW(), NOW())",
            [$outletId, $retailer->id, $province->id, $district->id]
        );
    } else {
        $outlet = Outlet::withoutGlobalScopes()->create([
            'tenant_id' => $retailer->id, 'outlet_type' => 'retail_branch', 'name' => 'Disputed Outlet',
            'address' => '9 Dispute Rd', 'province_id' => $province->id, 'district_id' => $district->id,
            'latitude' => -17.8292, 'longitude' => 31.0522, 'phone' => '+263779300001', 'is_active' => true,
        ]);
        $outletId = $outlet->id;
    }

    // 1. Seller verification submission + admin approval
    $verId = (string) $this->actingAs($userRetailer, 'sanctum')->postJson('/api/v1/marketplace/seller-verifications', [
        'business_registration_number' => 'ZW-CR14-99881',
        'verified_address' => '9 Dispute Rd, Harare',
    ])->assertStatus(201)->assertJsonPath('verification.status', 'pending')->json('verification.id');

    // Non-admin cannot review verification (403)
    $this->actingAs($userRetailer, 'sanctum')->postJson("/api/v1/marketplace/seller-verifications/{$verId}/review", [
        'decision' => 'verified',
    ])->assertStatus(403);

    // Platform admin approves verification
    $this->actingAs($platformAdmin, 'sanctum')->postJson("/api/v1/marketplace/seller-verifications/{$verId}/review", [
        'decision' => 'verified',
    ])->assertStatus(200)->assertJsonPath('verification.status', 'verified');

    // 2. Farmer opens dispute on an active order -> DISPUTED
    $otp = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263779400001'])->assertStatus(200)->json('debug_otp');
    $token = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263779400001', 'otp' => $otp])->assertStatus(200)->json('token');
    $farmer = Farmer::findOrCreateByPhone('+263779400001');

    $req = MarketplaceRequest::withoutGlobalScopes()->create([
        'farmer_id' => $farmer->id, 'request_type' => 'stock', 'status' => MarketplaceRequestStatus::OFFER_ACCEPTED,
        'source' => 'web', 'province_id' => $province->id, 'district_id' => $district->id, 'currency' => 'USD', 'current_ring' => 1,
    ]);
    $offer = Offer::withoutGlobalScopes()->create([
        'request_id' => $req->id, 'organisation_id' => $retailer->id, 'outlet_id' => $outletId,
        'status' => 'accepted', 'unit_price_minor' => 2000, 'total_price_minor' => 2000, 'currency' => 'USD',
        'quantity_available_scaled' => 100, 'fulfilment_type' => 'pickup', 'delivery_fee_minor' => 0, 'valid_until' => now()->addHours(12),
    ]);
    $order = MarketplaceOrder::withoutGlobalScopes()->create([
        'request_id' => $req->id, 'offer_id' => $offer->id, 'farmer_id' => $farmer->id,
        'organisation_id' => $retailer->id, 'outlet_id' => $outletId, 'status' => 'created',
        'total_amount_minor' => 2000, 'delivery_fee_minor' => 0, 'currency' => 'USD',
        'fulfilment_type' => 'pickup', 'payment_method' => 'ecocash', 'payment_status' => 'paid',
    ]);

    $this->withToken($token)->postJson("/api/v1/marketplace/orders/{$order->id}/dispute", [
        'reason' => 'Seller handed over expired chemical batch.',
    ])->assertStatus(200)->assertJsonPath('order.status', 'disputed');

    expect($req->fresh()->status)->toBe(MarketplaceRequestStatus::DISPUTED);

    // Non-admin cannot resolve dispute (403)
    $this->actingAs($userRetailer, 'sanctum')->postJson("/api/v1/marketplace/orders/{$order->id}/dispute/resolve", [
        'resolution' => 'complete',
        'notes' => 'Self-resolving',
    ])->assertStatus(403);

    // Platform admin cancels order, refunds payment, and suspends seller
    $this->actingAs($platformAdmin, 'sanctum')->postJson("/api/v1/marketplace/orders/{$order->id}/dispute/resolve", [
        'resolution' => 'cancel',
        'notes' => 'Confirmed expired product batch; seller suspended pending audit.',
        'suspend_seller' => true,
    ])->assertStatus(200)
        ->assertJsonPath('order.status', 'cancelled')
        ->assertJsonPath('order.payment_status', 'refunded');

    expect($req->fresh()->status)->toBe(MarketplaceRequestStatus::CANCELLED)
        ->and(SellerVerification::query()->where('organisation_id', '=', $retailer->id)->where('status', '=', 'suspended')->exists())->toBeTrue()
        ->and(OrderEvent::query()->where('order_id', '=', $order->id)->where('event_type', '=', 'dispute_resolved')->exists())->toBeTrue()
        ->and(AuditLog::withoutGlobalScopes()->where('event_type', '=', 'marketplace_dispute_resolved')->exists())->toBeTrue();

    // 3. Suspended seller MUST be excluded from all future Ring-1 broadcasts
    $nextReq = MarketplaceRequest::withoutGlobalScopes()->create([
        'farmer_id' => $farmer->id, 'request_type' => 'stock', 'status' => MarketplaceRequestStatus::DRAFT,
        'source' => 'web', 'province_id' => $province->id, 'district_id' => $district->id,
        'approx_latitude' => -17.8292, 'approx_longitude' => 31.0522, 'currency' => 'USD', 'current_ring' => 0,
    ]);
    RequestItem::query()->create([
        'request_id' => $nextReq->id, 'item_name' => 'Maize Seed 10kg', 'quantity_scaled' => 100, 'unit' => 'bags',
    ]);

    $notified = app(MarketplaceBroadcastService::class)->broadcastRing($nextReq, 1);
    expect($notified)->toBeEmpty();
});
