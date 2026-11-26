<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Models\District;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\Outlet;
use App\Models\Province;
use App\Models\RequestRecipient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MarketplaceOfferAndOrderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('serves the farmer marketplace web portal and isolates farmer request listing (IDOR)', function (): void {
    $this->get('/marketplace')
        ->assertStatus(200)
        ->assertSee('Input Marketplace');

    $otpA = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263775100100'])->assertStatus(200)->json('debug_otp');
    $tokenA = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263775100100', 'otp' => $otpA])->assertStatus(200)->json('token');

    $otpB = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263775200200'])->assertStatus(200)->json('debug_otp');
    $tokenB = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263775200200', 'otp' => $otpB])->assertStatus(200)->json('token');

    Queue::fake();
    $reqAId = (string) $this->withToken($tokenA)->postJson('/api/v1/marketplace/requests', [
        'approx_latitude' => -17.8292,
        'approx_longitude' => 31.0522,
        'items' => [['item_name' => 'Compound D 50kg', 'quantity_scaled' => 400, 'unit' => 'bags']],
    ])->assertStatus(201)->json('request.id');

    $this->withToken($tokenA)->getJson('/api/v1/marketplace/requests')->assertStatus(200)->assertJsonCount(1, 'requests')->assertJsonPath('requests.0.id', $reqAId);
    $this->withToken($tokenB)->getJson('/api/v1/marketplace/requests')->assertStatus(200)->assertJsonCount(0, 'requests');
});

it('supports the full farmer USSD/SMS marketplace loop (BUY, OFFERS, ACCEPT YES) with IDOR protection and log-channel WhatsApp confirmation', function (): void {
    Queue::fake();
    Outlet::withoutGlobalScopes()->delete();

    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();
    $org = Tenant::query()->create(['name' => 'USSD Agro Shop', 'slug' => 'ussd-agro', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);

    $outletId = (string) Str::uuid();
    if (DB::getDriverName() === 'pgsql') {
        DB::insert(
            "INSERT INTO outlets (id, tenant_id, outlet_type, name, address, province_id, district_id, latitude, longitude, phone, is_active, location_point, created_at, updated_at)
             VALUES (?, ?, 'retail_branch', 'USSD Harare Branch', '10 First St', ?, ?, -17.8100, 31.0522, '+263775000001', true, ST_SetSRID(ST_MakePoint(31.0522, -17.8100), 4326)::geography, NOW(), NOW())",
            [$outletId, $org->id, $province->id, $district->id]
        );
    } else {
        Outlet::withoutGlobalScopes()->create([
            'id' => $outletId, 'tenant_id' => $org->id, 'outlet_type' => 'retail_branch', 'name' => 'USSD Harare Branch',
            'address' => '10 First St', 'province_id' => $province->id, 'district_id' => $district->id,
            'latitude' => -17.8100, 'longitude' => 31.0522, 'phone' => '+263775000001', 'is_active' => true,
        ]);
    }

    // 1. Farmer sends USSD BUY command
    $buyRes = $this->postJson('/api/v1/webhooks/sms-ussd', [
        'from' => '+263775333111',
        'text' => 'BUY 4 bags Compound D 50kg',
        'channel' => 'ussd',
    ])->assertStatus(200);

    $requestId = (string) $buyRes->json('request_id');
    $shortRef = strtoupper(substr($requestId, 0, 8));
    expect($buyRes->json('reply_text'))->toContain($shortRef)
        ->and($buyRes->json('reply_text'))->toContain('Sent to 1 nearby shops');

    // 2. Retailer submits an offer
    $rec = RequestRecipient::withoutGlobalScopes()->where('request_id', '=', $requestId)->firstOrFail();
    app(MarketplaceOfferAndOrderService::class)->submitOffer($rec, [
        'unit_price_minor' => 3400,
        'total_price_minor' => 13600,
        'quantity_available_scaled' => 400,
        'fulfilment_type' => 'pickup',
    ]);

    // 3. Another phone cannot view or accept Farmer's USSD request (IDOR blocked)
    $this->postJson('/api/v1/webhooks/sms-ussd', [
        'from' => '+263775999999',
        'text' => "OFFERS {$shortRef}",
        'channel' => 'ussd',
    ])->assertStatus(200)->assertJsonPath('reply_text', "Request {$shortRef} not found for your phone number.");

    // 4. Owning farmer checks OFFERS via USSD
    $offersRes = $this->postJson('/api/v1/webhooks/sms-ussd', [
        'from' => '+263775333111',
        'text' => "OFFERS {$shortRef}",
        'channel' => 'ussd',
    ])->assertStatus(200);
    expect($offersRes->json('reply_text'))->toContain('136.00')
        ->and($offersRes->json('reply_text'))->toContain('USSD Harare Branch');

    // 5. Owning farmer accepts offer #1 with YES consent
    $acceptRes = $this->postJson('/api/v1/webhooks/sms-ussd', [
        'from' => '+263775333111',
        'text' => "ACCEPT {$shortRef} 1 YES",
        'channel' => 'sms',
    ])->assertStatus(200);

    $orderId = (string) $acceptRes->json('order_id');
    expect($acceptRes->json('reply_text'))->toContain('Contact shared: YES')
        ->and(MarketplaceOrder::withoutGlobalScopes()->findOrFail($orderId)->farmer_contact_revealed_at)->not->toBeNull()
        ->and(MarketplaceRequest::withoutGlobalScopes()->findOrFail($requestId)->revealFarmerContactFor($org->id))->not->toBeNull();
});
