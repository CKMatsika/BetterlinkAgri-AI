<?php

declare(strict_types=1);

use App\Enums\OrganisationType;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('serves the BetterlinkAgri AI Portal Hub and upgraded Farmer Marketplace tabs, and creates a working Platform Super-Admin via Artisan', function (): void {
    // 1. Portal Hub at /
    $this->get('/')
        ->assertOk()
        ->assertSee('Agri-Ecosystem Portal Hub')
        ->assertSee('/planner')
        ->assertSee('/marketplace')
        ->assertSee('/platform')
        ->assertSee('/admin')
        ->assertSee('/outlet');

    // 2. Farmer Marketplace web portal includes M1 Inputs, M4 Services & Logistics, and M5 Offtake & Agri-Finance tabs
    $this->get('/marketplace')
        ->assertOk()
        ->assertSee('Inputs Marketplace')
        ->assertSee('Services &amp; Logistics (M4)', false)
        ->assertSee('Offtake &amp; Agri-Finance (M5)', false);

    // 3. Production super-admin bootstrap command
    $this->artisan('app:create-super-admin', [
        '--email' => 'founder@betterlinkagr.co.zw',
        '--name' => 'Founder Super-Admin',
        '--password' => 'StrongPass@2026!',
    ])->assertSuccessful();

    $superAdmin = User::withoutGlobalScopes()->where('email', '=', 'founder@betterlinkagr.co.zw')->firstOrFail();
    expect($superAdmin->role)->toBe(UserRole::SUPER_ADMIN)
        ->and($superAdmin->tenant_id)->toBeNull();

    // 4. Platform Panel M3 Governance pages render cleanly for Super-Admin
    $this->actingAs($superAdmin)->get('/platform/seller-verifications')->assertOk();
    $this->actingAs($superAdmin)->get('/platform/marketplace-disputes')->assertOk();

    // 5. Tenant Panel M2-M5 screens enforce strict organisation-type visibility
    $retailer = Tenant::query()->create(['name' => 'UI Retailer', 'slug' => 'ui-ret', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::RETAILER]);
    $offtaker = Tenant::query()->create(['name' => 'UI Offtaker', 'slug' => 'ui-off', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::OFFTAKER]);
    $bank = Tenant::query()->create(['name' => 'UI Bank', 'slug' => 'ui-bank', 'tenant_type' => 'agro_dealer', 'organisation_type' => OrganisationType::BANK]);

    $makeUser = static fn (Tenant $t, string $email): User => User::withoutGlobalScopes()->create([
        'tenant_id' => $t->id, 'name' => $t->name . ' Admin', 'email' => $email,
        'password' => bcrypt('Secret@2026!'), 'role' => UserRole::TENANT_ADMIN,
    ]);

    $retUser = $makeUser($retailer, 'ui-ret@example.test');
    $offUser = $makeUser($offtaker, 'ui-off@example.test');
    $bankUser = $makeUser($bank, 'ui-bank@example.test');

    // Retailer accesses M2 Demand/Stock Signals & M4 Service Bookings, blocked from M5 Finance
    $this->flushSession();
    $this->actingAs($retUser)->get('/admin/ui-ret/demand-aggregates')->assertOk();
    $this->actingAs($retUser)->get('/admin/ui-ret/stock-signals')->assertOk();
    $this->actingAs($retUser)->get('/admin/ui-ret/service-bookings')->assertOk();
    $this->actingAs($retUser)->get('/admin/ui-ret/finance-applications')->assertForbidden();

    // Offtaker accesses M5 Forward Quotes
    $this->flushSession();
    $this->actingAs($offUser)->get('/admin/ui-off/forward-quotes')->assertOk();

    // Bank accesses M5 Finance Applications, blocked from M2 Demand Aggregates & Stock Signals (403)
    $this->flushSession();
    $this->actingAs($bankUser)->get('/admin/ui-bank/finance-applications')->assertOk();
    $this->actingAs($bankUser)->get('/admin/ui-bank/demand-aggregates')->assertForbidden();
    $this->actingAs($bankUser)->get('/admin/ui-bank/stock-signals')->assertForbidden();
});
