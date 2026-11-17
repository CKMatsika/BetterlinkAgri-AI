<?php

declare(strict_types=1);

use App\Actions\Consent\RecordLocationConsentAction;
use App\Models\Farmer;
use App\Models\FarmerLocation;
use App\Models\Outlet;
use App\Models\OutletStock;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Ward;
use App\Services\Outlets\OutletFinderService;
use Database\Seeders\DatabaseSeeder;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('finds nearest stocked outlets, flags stale stock > 48h, and never shares exact farmer coordinates before consent', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $glendaleWard = Ward::query()->where('name', 'LIKE', '%Glendale%')->firstOrFail();

    $farmer = Farmer::findOrCreateByPhone('+263774111222', 'Tendai Choga');
    $farmer->update(['ward_id' => $glendaleWard->id]);

    // Save exact farmer coordinates in Glendale, Mazowe (-17.3580, 31.0690)
    // WITHOUT granting share_exact_with_outlet_consent
    app(RecordLocationConsentAction::class)->execute(
        tenantId: $tenant->id,
        farmer: $farmer,
        gpsCaptureConsent: true,
        shareExactWithOutletConsent: false
    );

    FarmerLocation::query()->create([
        'farmer_id' => $farmer->id,
        'tenant_id' => $tenant->id,
        'ward_id' => $glendaleWard->id,
        'latitude_encrypted' => '-17.3580000',
        'longitude_encrypted' => '31.0690000',
        'latitude' => -17.3580000,
        'longitude' => 31.0690000,
        'source' => 'whatsapp_pin',
        'is_approximate' => false,
        'captured_at' => now(),
    ]);

    $product = Product::withoutGlobalScopes()
        ->where('tenant_id', '=', $tenant->id)
        ->where('sku', '=', 'ZIM-EMAMEC-5SG')
        ->firstOrFail();

    // Make Bindura Depot stock stale (72 hours old > 48h freshness limit)
    $binduraOutlet = Outlet::withoutGlobalScopes()->where('name', 'LIKE', '%Bindura%')->firstOrFail();
    OutletStock::withoutGlobalScopes()
        ->where('outlet_id', '=', $binduraOutlet->id)
        ->update(['last_updated_at' => now()->subHours(72)]);

    $finder = app(OutletFinderService::class);
    $result = $finder->findNearestStockedOutlets(
        tenant: $tenant,
        farmer: $farmer,
        productIds: [$product->id]
    );

    expect($result['fallback_required'])->toBeFalse()
        ->and($result['radius_used_km'])->toBe('25 km')
        ->and($result['outlets'])->not->toBeEmpty();

    $nearest = $result['outlets'][0];
    expect($nearest->outletName)->toContain('Glendale')
        // Verify PRIVACY GUARDRAIL: exact coordinates (-17.35800, 31.06900) are NEVER in the outlet disclosure string!
        ->and($nearest->farmerLocationDisclosureForOutlet)->not->toContain('-17.358')
        ->and($nearest->farmerLocationDisclosureForOutlet)->toContain('exact GPS hidden until farmer contact consent');

    // Now test stale stock warning on Bindura when searching with 50km radius
    $all50km = $finder->findNearestStockedOutlets(
        tenant: $tenant,
        farmer: $farmer,
        productIds: [$product->id],
        overrideLat: -17.3020,
        overrideLng: 31.3310
    );

    $binduraResult = collect($all50km['outlets'])->first(fn ($o) => str_contains($o->outletName, 'Bindura'));
    expect($binduraResult)->not->toBeNull()
        ->and($binduraResult->basketStockItems[0]['is_fresh'])->toBeFalse()
        ->and($binduraResult->basketStockItems[0]['stale_warning'])->toContain('please call to confirm');
});
