<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Farmer;
use App\Models\FarmPlan;
use App\Models\PlanEnterprise;
use App\Models\PlanRequirement;
use App\Models\Province;
use App\Models\ServiceProvider;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PlanningTestFixturesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
    $this->seed(PlanningTestFixturesSeeder::class);
});

it('discovers nearby service providers spatially, links plan_requirements.booking_id, enforces contact consent, and runs provider lifecycle with IDOR checks', function (): void {
    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    $orgA = Tenant::query()->create(['name' => 'Harare Tillage Co', 'slug' => 'm4-tillage-a', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $orgB = Tenant::query()->create(['name' => 'Other Service Co', 'slug' => 'm4-tillage-b', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);

    $userOrgA = User::withoutGlobalScopes()->create([
        'tenant_id' => $orgA->id, 'name' => 'Provider A', 'email' => 'm4-prova@example.test',
        'password' => bcrypt('secret1234'), 'role' => 'tenant_admin',
    ]);
    $userOrgB = User::withoutGlobalScopes()->create([
        'tenant_id' => $orgB->id, 'name' => 'Provider B', 'email' => 'm4-provb@example.test',
        'password' => bcrypt('secret1234'), 'role' => 'tenant_admin',
    ]);

    $createProvider = function (string $tenantId, string $name, float $lat, float $lng, int $rateMinor, bool $verified, bool $active) use ($province, $district): string {
        $id = (string) Str::uuid();
        if (DB::getDriverName() === 'pgsql') {
            DB::insert(
                "INSERT INTO service_providers (id, tenant_id, name, service_type, province_id, district_id, base_latitude, base_longitude, coverage_radius_km, unit_rate_minor, rate_unit, currency, is_active, is_verified, location_point, created_at, updated_at)
                 VALUES (?, ?, ?, 'tillage', ?, ?, ?, ?, 50, ?, 'per_ha', 'USD', ?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, NOW(), NOW())",
                [$id, $tenantId, $name, $province->id, $district->id, $lat, $lng, $rateMinor, $active, $verified, $lng, $lat]
            );

            return $id;
        }

        $p = ServiceProvider::query()->create([
            'tenant_id' => $tenantId, 'name' => $name, 'service_type' => 'tillage',
            'province_id' => $province->id, 'district_id' => $district->id,
            'base_latitude' => $lat, 'base_longitude' => $lng, 'coverage_radius_km' => 50,
            'unit_rate_minor' => $rateMinor, 'rate_unit' => 'per_ha', 'currency' => 'USD',
            'is_active' => $active, 'is_verified' => $verified,
        ]);

        return $p->id;
    };

    $verifiedId = $createProvider($orgA->id, 'Verified Tillage', -17.8292, 31.0522, 6500, true, true);
    $unverifiedId = $createProvider($orgB->id, 'Unverified Cheap Tillage', -17.8292, 31.0522, 5000, false, true);
    $farId = $createProvider($orgB->id, 'Bulawayo Tillage (Too Far)', -20.1325, 28.6265, 4500, true, true);
    $inactiveId = $createProvider($orgB->id, 'Inactive Tillage', -17.8292, 31.0522, 4000, true, false);

    $otpA = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263775100100'])->assertStatus(200)->json('debug_otp');
    $tokenA = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263775100100', 'otp' => $otpA])->assertStatus(200)->json('token');
    $farmerA = Farmer::findOrCreateByPhone('+263775100100');

    $otpB = $this->postJson('/api/v1/planner/auth/request-otp', ['phone' => '+263775100200'])->assertStatus(200)->json('debug_otp');
    $tokenB = (string) $this->postJson('/api/v1/planner/auth/verify-otp', ['phone' => '+263775100200', 'otp' => $otpB])->assertStatus(200)->json('token');

    // 1. Spatial search returns verified first, then unverified; excludes far (>50km) and inactive
    $searchRes = $this->withToken($tokenA)
        ->getJson('/api/v1/marketplace/services/providers?service_type=tillage&latitude=-17.8292&longitude=31.0522')
        ->assertStatus(200);

    $returnedIds = collect($searchRes->json('providers'))->pluck('id')->all();
    expect($returnedIds)->toBe([$verifiedId, $unverifiedId])
        ->and($returnedIds)->not->toContain($farId)
        ->and($returnedIds)->not->toContain($inactiveId);

    // 2. PlanRequirement linking + IDOR check
    $broilers = PlanEnterprise::query()->where('code', '=', 'broilers')->firstOrFail();
    $plan = FarmPlan::query()->create([
        'tenant_id' => $orgA->id, 'farmer_id' => $farmerA->id, 'enterprise_id' => $broilers->id,
        'title' => 'M4 Plan (test fixture)', 'scale_value' => 2, 'scale_unit' => 'ha',
    ]);
    $planReq = PlanRequirement::query()->create([
        'farm_plan_id' => $plan->id, 'requirement_type' => 'service', 'item_code' => 'tillage_disc',
        'item_name' => 'Disc Ploughing', 'quantity_scaled' => 250, 'unit' => 'ha',
        'needed_by_date' => now()->addDays(5)->toDateString(), 'activation_at' => now(), 'status' => 'scheduled',
    ]);

    // Farmer B cannot book against Farmer A's plan requirement (403)
    $this->withToken($tokenB)->postJson('/api/v1/marketplace/bookings', [
        'service_provider_id' => $verifiedId,
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'quantity_scaled' => 250,
        'plan_requirement_id' => $planReq->id,
    ])->assertStatus(403);

    // Farmer A books WITHOUT contact consent -> provider sees farmer_phone = null
    $bookRes1 = $this->withToken($tokenA)->postJson('/api/v1/marketplace/bookings', [
        'service_provider_id' => $verifiedId,
        'scheduled_date' => now()->addDays(3)->toDateString(),
        'quantity_scaled' => 250, // 2.5 ha * $65.00 = $162.50 -> 16250 minor
        'unit' => 'ha',
        'plan_requirement_id' => $planReq->id,
        'grant_contact_consent' => false,
    ])->assertStatus(201);

    $bookingId = (string) $bookRes1->json('booking.id');
    expect($bookRes1->json('booking.quoted_amount_minor'))->toBe(16250)
        ->and($bookRes1->json('booking.farmer_contact_revealed'))->toBeFalse()
        ->and($planReq->fresh()->booking_id)->toBe($bookingId)
        ->and($planReq->fresh()->status)->toBe('booked');

    $provList1 = $this->actingAs($userOrgA, 'sanctum')->getJson('/api/v1/marketplace/provider/bookings')->assertStatus(200);
    expect($provList1->json('bookings.0.farmer_phone'))->toBeNull();

    // Cross-org provider B cannot update Provider A's booking (403)
    $this->actingAs($userOrgB, 'sanctum')->postJson("/api/v1/marketplace/provider/bookings/{$bookingId}/status", [
        'status' => 'confirmed',
    ])->assertStatus(403);

    // Illegal jump requested -> completed rejected (422)
    $this->actingAs($userOrgA, 'sanctum')->postJson("/api/v1/marketplace/provider/bookings/{$bookingId}/status", [
        'status' => 'completed',
    ])->assertStatus(422);

    // Valid lifecycle: requested -> confirmed -> in_progress -> completed
    $this->actingAs($userOrgA, 'sanctum')->postJson("/api/v1/marketplace/provider/bookings/{$bookingId}/status", ['status' => 'confirmed'])->assertStatus(200);
    $this->actingAs($userOrgA, 'sanctum')->postJson("/api/v1/marketplace/provider/bookings/{$bookingId}/status", ['status' => 'in_progress'])->assertStatus(200);
    $this->actingAs($userOrgA, 'sanctum')->postJson("/api/v1/marketplace/provider/bookings/{$bookingId}/status", ['status' => 'completed'])
        ->assertStatus(200)
        ->assertJsonPath('booking.status', 'completed');

    expect(AuditLog::withoutGlobalScopes()->where('event_type', '=', 'service_booking_status_changed')->count())->toBe(3);

    // 3. Farmer A books second job WITH contact consent -> provider sees phone
    $bookRes2 = $this->withToken($tokenA)->postJson('/api/v1/marketplace/bookings', [
        'service_provider_id' => $verifiedId,
        'scheduled_date' => now()->addDays(6)->toDateString(),
        'quantity_scaled' => 100,
        'grant_contact_consent' => true,
    ])->assertStatus(201);
    expect($bookRes2->json('booking.farmer_contact_revealed'))->toBeTrue();

    $provList2 = $this->actingAs($userOrgA, 'sanctum')->getJson('/api/v1/marketplace/provider/bookings')->assertStatus(200);
    $phones = collect($provList2->json('bookings'))->pluck('farmer_phone')->filter()->values()->all();
    expect($phones)->toContain('+263775100100');
});
