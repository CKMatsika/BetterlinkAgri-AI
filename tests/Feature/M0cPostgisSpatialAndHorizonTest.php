<?php

declare(strict_types=1);

use App\Models\District;
use App\Models\Farmer;
use App\Models\Outlet;
use App\Models\OutletProductAuthorisation;
use App\Models\OutletStock;
use App\Models\Product;
use App\Models\Province;
use App\Models\Tenant;
use App\Services\Outlets\OutletFinderService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('verifies PostGIS ring boundaries (10/25/50 km), ties, no outlets in range, inactive/unauthorised exclusion, 1000+ outlets, and GiST index EXPLAIN', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostGIS spatial & GiST EXPLAIN test requires PostgreSQL.');
    }

    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $product = Product::withoutGlobalScopes()->where('tenant_id', '=', $tenant->id)->firstOrFail();
    $province = Province::query()->firstOrFail();
    $district = District::query()->where('province_id', '=', $province->id)->firstOrFail();

    // Remove seeded outlets so ring tests are completely isolated
    OutletStock::withoutGlobalScopes()->delete();
    OutletProductAuthorisation::withoutGlobalScopes()->delete();
    Outlet::withoutGlobalScopes()->delete();

    // Farmer at Harare (-17.8292, 31.0522)
    $farmerLat = -17.8292;
    $farmerLng = 31.0522;
    $farmer = Farmer::findOrCreateByPhone('+263779000111', 'Spatial Test Farmer');

    $makeOutlet = function (string $name, float $lat, float $lng, bool $active = true, bool $authorised = true) use ($tenant, $product, $province, $district): Outlet {
        $id = (string) Str::uuid();
        DB::insert(
            "INSERT INTO outlets (id, tenant_id, outlet_type, name, address, province_id, district_id, latitude, longitude, phone, is_active, location_point, created_at, updated_at)
             VALUES (?, ?, 'retail_branch', ?, 'Test Address', ?, ?, ?, ?, '+263770000000', ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, NOW(), NOW())",
            [$id, $tenant->id, $name, $province->id, $district->id, $lat, $lng, $active ? 'true' : 'false', $lng, $lat]
        );

        $outlet = Outlet::withoutGlobalScopes()->findOrFail($id);

        if ($authorised) {
            OutletProductAuthorisation::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'outlet_id' => $outlet->id,
                'product_id' => $product->id,
                'is_authorised' => true,
            ]);
        }

        OutletStock::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'outlet_id' => $outlet->id,
            'product_id' => $product->id,
            'pack_size' => '1L',
            'quantity_band' => 'in_stock',
            'exact_quantity' => 50,
            'price' => 12.50,
            'currency' => 'USD',
            'source' => 'outlet_portal',
            'last_updated_at' => now(),
        ]);

        return $outlet;
    };

    config()->set('betterlink.outlets.search_radii_km', [10, 25, 50]);
    /** @var OutletFinderService $finder */
    $finder = app(OutletFinderService::class);

    // 1. Inactive & unauthorised outlets at 1 km must be excluded even when closest
    $makeOutlet('Inactive Close Outlet', -17.8300, 31.0522, active: false, authorised: true);
    $makeOutlet('Unauthorised Close Outlet', -17.8310, 31.0522, active: true, authorised: false);

    // 2. No outlets in range when only outlet is 80 km away (and no ward province fallback)
    $farOutlet = $makeOutlet('Far 80km Outlet', -17.1000, 31.0522);
    $noRange = $finder->findNearestStockedOutlets($tenant, $farmer, [$product->id], $farmerLat, $farmerLng, null);
    expect($noRange['outlets'])->toBeEmpty()
        ->and($noRange['fallback_required'])->toBeTrue();

    // 3. Outlet at ~35 km -> hits 50 km ring
    $ring50 = $makeOutlet('Ring 35km Outlet', -17.5150, 31.0522);
    $res50 = $finder->findNearestStockedOutlets($tenant, $farmer, [$product->id], $farmerLat, $farmerLng, null);
    expect($res50['radius_used_km'])->toBe('50 km')
        ->and($res50['outlets'][0]->outletId)->toBe($ring50->id);

    // 4. Outlet at ~18 km -> hits 25 km ring
    $ring25 = $makeOutlet('Ring 18km Outlet', -17.6670, 31.0522);
    $res25 = $finder->findNearestStockedOutlets($tenant, $farmer, [$product->id], $farmerLat, $farmerLng, null);
    expect($res25['radius_used_km'])->toBe('25 km')
        ->and($res25['outlets'][0]->outletId)->toBe($ring25->id);

    // 5. Tie at ~5 km -> hits 10 km ring and returns both tied outlets
    $tieA = $makeOutlet('Tie Outlet A', -17.7842, 31.0522);
    $tieB = $makeOutlet('Tie Outlet B', -17.7842, 31.0522);
    $res10 = $finder->findNearestStockedOutlets($tenant, $farmer, [$product->id], $farmerLat, $farmerLng, null);
    expect($res10['radius_used_km'])->toBe('10 km')
        ->and(count($res10['outlets']))->toBe(2)
        ->and(collect($res10['outlets'])->pluck('outletId')->all())->toContain($tieA->id, $tieB->id);

    // 6. Bulk insert 1,050 outlets and verify EXPLAIN uses GiST index idx_outlets_location_gist
    $rows = [];
    $now = now()->toDateTimeString();
    for ($i = 0; $i < 1050; $i++) {
        $lat = -19.5 + (($i % 100) * 0.02);
        $lng = 29.0 + (intdiv($i, 100) * 0.1);
        $rows[] = sprintf(
            "('%s', '%s', 'retail_branch', 'Bulk %d', 'Addr', '%s', '%s', %F, %F, '+263770000000', true, ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography, '%s', '%s')",
            (string) Str::uuid(),
            $tenant->id,
            $i,
            $province->id,
            $district->id,
            $lat,
            $lng,
            $lng,
            $lat,
            $now,
            $now
        );
    }
    DB::statement('INSERT INTO outlets (id, tenant_id, outlet_type, name, address, province_id, district_id, latitude, longitude, phone, is_active, location_point, created_at, updated_at) VALUES ' . implode(',', $rows));
    DB::statement('ANALYZE outlets');

    $planRows = DB::select(
        'EXPLAIN SELECT id FROM outlets WHERE tenant_id = ? AND is_active = true AND location_point IS NOT NULL AND ST_DWithin(location_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, 10000)',
        [$tenant->id, $farmerLng, $farmerLat]
    );

    $explainText = implode("\n", array_map(fn ($r) => (string) ($r->{'QUERY PLAN'} ?? reset($r)), $planRows));
    expect(Outlet::withoutGlobalScopes()->count())->toBeGreaterThanOrEqual(1050)
        ->and($explainText)->toContain('idx_outlets_location_gist');
});
