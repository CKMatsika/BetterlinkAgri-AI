<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Events\Marketplace\OfferStatusChangedEvent;
use App\Events\Marketplace\OutletRequestAlertedEvent;
use App\Events\Marketplace\RequestCountersUpdatedEvent;
use App\Jobs\Marketplace\SendRetailerMarketplaceAlertJob;
use App\Models\District;
use App\Models\MarketplaceNotification;
use App\Models\Outlet;
use App\Models\Province;
use App\Models\RequestRecipient;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

function m1cOutlet(Tenant $org, Province $province, District $district, string $name, float $lat, float $lng): Outlet
{
    $id = (string) Str::uuid();
    if (DB::getDriverName() === 'pgsql') {
        DB::insert(
            "INSERT INTO outlets (id, tenant_id, outlet_type, name, address, province_id, district_id, latitude, longitude, phone, whatsapp_number, is_active, location_point, created_at, updated_at)
             VALUES (?, ?, 'retail_branch', ?, '1 Market St', ?, ?, ?, ?, '+263771900001', '+263771900001', true, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, NOW(), NOW())",
            [$id, $org->id, $name, $province->id, $district->id, $lat, $lng, $lng, $lat]
        );

        return Outlet::withoutGlobalScopes()->findOrFail($id);
    }

    return Outlet::withoutGlobalScopes()->create([
        'tenant_id' => $org->id, 'outlet_type' => 'retail_branch', 'name' => $name,
        'address' => '1 Market St', 'province_id' => $province->id, 'district_id' => $district->id,
        'latitude' => $lat, 'longitude' => $lng, 'phone' => '+263771900001',
        'whatsapp_number' => '+263771900001', 'is_active' => true,
    ]);
}

function m1cFarmerLogin(mixed $test, string $phone): string
{
    $otp = $test->postJson('/api/v1/planner/auth/request-otp', ['phone' => $phone])->assertStatus(200)->json('debug_otp');

    return (string) $test->postJson('/api/v1/planner/auth/verify-otp', ['phone' => $phone, 'otp' => $otp])->assertStatus(200)->json('token');
}

it('emits Reverb events without leaking farmer PII, dispatches WhatsApp/SMS retailer alerts, and enforces tenant isolation on outlet alerts and counters', function (): void {
    Event::fake([
        OutletRequestAlertedEvent::class,
        RequestCountersUpdatedEvent::class,
        OfferStatusChangedEvent::class,
    ]);
    Queue::fake();
    Outlet::withoutGlobalScopes()->delete();

    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $orgA = Tenant::query()->create(['name' => 'Reverb Shop A', 'slug' => 'reverb-a', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $orgB = Tenant::query()->create(['name' => 'Reverb Shop B', 'slug' => 'reverb-b', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::INDEPENDENT_RETAILER]);

    $outletA = m1cOutlet($orgA, $province, $district, 'Outlet A', -17.8100, 31.0522);
    $outletB = m1cOutlet($orgB, $province, $district, 'Outlet B', -17.8100, 31.0522);

    $userA = User::query()->create(['tenant_id' => $orgA->id, 'name' => 'User A', 'email' => 'ua@reverb.zw', 'password' => bcrypt('secret'), 'role' => 'outlet_manager']);
    $userB = User::query()->create(['tenant_id' => $orgB->id, 'name' => 'User B', 'email' => 'ub@reverb.zw', 'password' => bcrypt('secret'), 'role' => 'outlet_manager']);

    $farmerAToken = m1cFarmerLogin($this, '+263774100100');
    $farmerBToken = m1cFarmerLogin($this, '+263774200200');

    // 1. Farmer A creates request -> emits Reverb OutletRequestAlertedEvent (NO exact location/phone) + RequestCountersUpdatedEvent + WhatsApp & SMS jobs
    $res = $this->withToken($farmerAToken)->postJson('/api/v1/marketplace/requests', [
        'approx_latitude' => -17.8292,
        'approx_longitude' => 31.0522,
        'exact_location' => 'SECRET_FARM_COORDS_PLOT_88',
        'currency' => 'USD',
        'items' => [['category' => 'seed', 'item_name' => 'SC513 Maize Seed 10kg', 'quantity_scaled' => 200, 'unit' => 'bags']],
    ])->assertStatus(201);

    $requestId = (string) $res->json('request.id');

    Event::assertDispatched(OutletRequestAlertedEvent::class, function (OutletRequestAlertedEvent $e) use ($outletA): bool {
        $payload = json_encode($e->broadcastWith());

        return $e->outletId === $outletA->id
            && !str_contains((string) $payload, 'SECRET_FARM_COORDS_PLOT_88')
            && !str_contains((string) $payload, '+263774100100');
    });
    Event::assertDispatched(RequestCountersUpdatedEvent::class, fn ($e) => $e->requestId === $requestId && $e->counters['sent'] === 2);

    Queue::assertPushedOn('notifications', SendRetailerMarketplaceAlertJob::class, fn ($j) => $j->channel === 'whatsapp');
    Queue::assertPushedOn('notifications', SendRetailerMarketplaceAlertJob::class, fn ($j) => $j->channel === 'sms' && $j->delay !== null);

    // 2. Execute SendRetailerMarketplaceAlertJob -> records sent notification and recipient delivered_at
    $recA = RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->where('outlet_id', '=', $outletA->id)->firstOrFail();
    $recB = RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->where('outlet_id', '=', $outletB->id)->firstOrFail();

    (new SendRetailerMarketplaceAlertJob($recA->id, 'whatsapp'))->handle();
    expect($recA->fresh()->delivered_at)->not->toBeNull()
        ->and(MarketplaceNotification::query()->where('channel', '=', 'whatsapp')->where('status', '=', 'sent')->where('recipient_id', '=', $outletA->id)->exists())->toBeTrue();

    // 3. Outlet Portal alerts endpoint returns only own organisation's alerts and zero farmer PII
    $alertsRes = $this->actingAs($userA, 'sanctum')->getJson('/api/v1/marketplace/outlet/alerts')->assertStatus(200);
    expect(count($alertsRes->json('alerts')))->toBe(1)
        ->and($alertsRes->json('alerts.0.outlet_id'))->toBe($outletA->id)
        ->and(json_encode($alertsRes->json()))->not->toContain('SECRET_FARM_COORDS_PLOT_88');

    // 4. Counters polling endpoint enforces farmer ownership (Farmer B gets 403)
    $this->withToken($farmerAToken)->getJson("/api/v1/marketplace/requests/{$requestId}/counters")->assertStatus(200)->assertJsonPath('counters.sent', 2);
    $this->withToken($farmerBToken)->getJson("/api/v1/marketplace/requests/{$requestId}/counters")->assertStatus(403);

    // 5. Both outlets submit offers, Farmer A accepts A -> OfferStatusChangedEvent emitted for both accepted & closed
    $offerAId = (string) $this->actingAs($userA, 'sanctum')->postJson("/api/v1/marketplace/recipients/{$recA->id}/respond", [
        'action' => 'offer', 'unit_price_minor' => 2200, 'total_price_minor' => 4400, 'quantity_available_scaled' => 200,
    ])->assertStatus(201)->json('offer.id');

    $offerBId = (string) $this->actingAs($userB, 'sanctum')->postJson("/api/v1/marketplace/recipients/{$recB->id}/respond", [
        'action' => 'offer', 'unit_price_minor' => 2500, 'total_price_minor' => 5000, 'quantity_available_scaled' => 200,
    ])->assertStatus(201)->json('offer.id');

    $this->withToken($farmerAToken)->postJson("/api/v1/marketplace/requests/{$requestId}/offers/{$offerAId}/accept")->assertStatus(200);

    Event::assertDispatched(OfferStatusChangedEvent::class, fn ($e) => $e->offerId === $offerAId && $e->status === 'accepted');
    Event::assertDispatched(OfferStatusChangedEvent::class, fn ($e) => $e->offerId === $offerBId && $e->status === 'closed');
});
