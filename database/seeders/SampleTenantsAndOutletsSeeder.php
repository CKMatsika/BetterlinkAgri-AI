<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RecommendationScope;
use App\Enums\TenantType;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\OutletUser;
use App\Models\Tenant;
use App\Models\TenantSupplyLink;
use App\Models\User;
use App\Models\Ward;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class SampleTenantsAndOutletsSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Manufacturer Tenant
        $manufacturer = Tenant::query()->updateOrCreate(
            ['slug' => 'zimcrop-chem'],
            [
                'name' => 'ZimCrop & Chem Manufacturers',
                'tenant_type' => TenantType::MANUFACTURER,
                'recommendation_scope' => RecommendationScope::OWN_BRANDS_ONLY,
                'whatsapp_phone_number_id' => '100000000000001',
                'usd_to_zig_rate' => 25.5000,
                'persona_config' => [
                    'agent_name' => 'Mudhumeni ZimCrop AI',
                    'tone' => 'warm, authoritative, practical Zimbabwean agronomist',
                    'languages' => ['en', 'sn', 'nd'],
                    'primary_color' => '#1B5E20',
                ],
                'boundaries_config' => [
                    'allowed_categories' => ['pest', 'disease', 'nutrient_deficiency', 'weed', 'livestock_condition'],
                    'high_confidence_threshold' => 0.80,
                    'min_confidence_threshold' => 0.55,
                    'max_clarifying_questions' => 5,
                    'stock_freshness_hours' => 48,
                    'outlet_sla_minutes' => 30,
                    'rep_sla_minutes' => 120,
                    'mandatory_disclaimer' => 'Always read the product label before mixing. Wear protective clothing (PPE) and keep chemicals away from children, water sources, and bees.',
                ],
                'outlet_ranking_weights' => [
                    'stock_confidence' => 0.35,
                    'distance' => 0.30,
                    'open_now' => 0.15,
                    'delivery' => 0.10,
                    'price' => 0.10,
                ],
                'crm_config' => [
                    'mode' => 'standalone',
                    'adapter' => 'csv',
                ],
                'is_active' => true,
            ]
        );

        // 2. National Agro-Dealer & Distributor Tenant (Default primary tenant)
        $agroDealer = Tenant::query()->updateOrCreate(
            ['slug' => 'farmserve-zimbabwe'],
            [
                'name' => 'FarmServe Zimbabwe Agro-Dealers',
                'tenant_type' => TenantType::AGRO_DEALER,
                'recommendation_scope' => RecommendationScope::OWN_PLUS_PARTNER_BRANDS,
                'whatsapp_phone_number_id' => '100000000000002',
                'usd_to_zig_rate' => 25.5000,
                'persona_config' => [
                    'agent_name' => 'FarmServe Mudhumeni / Umlimisi',
                    'tone' => 'helpful, clear, farmer-first Zimbabwean agro-dealer',
                    'languages' => ['en', 'sn', 'nd'],
                    'primary_color' => '#0D47A1',
                ],
                'boundaries_config' => [
                    'allowed_categories' => ['pest', 'disease', 'nutrient_deficiency', 'nutrient_toxicity', 'weed', 'abiotic_stress', 'livestock_condition'],
                    'high_confidence_threshold' => 0.80,
                    'min_confidence_threshold' => 0.55,
                    'max_clarifying_questions' => 5,
                    'stock_freshness_hours' => 48,
                    'outlet_sla_minutes' => 30,
                    'rep_sla_minutes' => 120,
                    'mandatory_disclaimer' => 'Read the Zimbabwe registered label carefully before use. Observe Pre-Harvest Intervals (PHI) and wear full PPE.',
                ],
                'outlet_ranking_weights' => [
                    'stock_confidence' => 0.35,
                    'distance' => 0.30,
                    'open_now' => 0.15,
                    'delivery' => 0.10,
                    'price' => 0.10,
                ],
                'crm_config' => [
                    'mode' => 'integrated',
                    'adapter' => 'webhook',
                ],
                'is_active' => true,
            ]
        );

        // 3. Supply Link between ZimCrop Manufacturer and FarmServe Agro-Dealer
        TenantSupplyLink::query()->updateOrCreate(
            [
                'manufacturer_tenant_id' => $manufacturer->id,
                'distributor_or_retailer_tenant_id' => $agroDealer->id,
            ],
            [
                'approved_product_ids_json' => [],
                'status' => 'active',
            ]
        );

        // 4. Platform & Tenant Staff Users
        User::query()->updateOrCreate(
            ['email' => 'superadmin@betterlinkagr.co.zw'],
            [
                'tenant_id' => null,
                'name' => 'Tendai Moyo (Platform Super-Admin)',
                'password' => Hash::make('Betterlink@2026!'),
                'phone_encrypted' => '+263772100001',
                'whatsapp_number' => '+263772100001',
                'role' => UserRole::SUPER_ADMIN,
                'languages_json' => ['en', 'sn', 'nd'],
                'is_available_for_handoff' => false,
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'admin@farmserve.co.zw'],
            [
                'tenant_id' => $agroDealer->id,
                'name' => 'Rudo Chikwanda (Tenant Admin)',
                'password' => Hash::make('FarmServe@2026!'),
                'phone_encrypted' => '+263772100002',
                'whatsapp_number' => '+263772100002',
                'role' => UserRole::TENANT_ADMIN,
                'languages_json' => ['en', 'sn'],
                'is_available_for_handoff' => true,
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'agronomist@farmserve.co.zw'],
            [
                'tenant_id' => $agroDealer->id,
                'name' => 'Dr. Farai Mutasa (Senior Agronomist)',
                'password' => Hash::make('FarmServe@2026!'),
                'phone_encrypted' => '+263772100003',
                'whatsapp_number' => '+263772100003',
                'role' => UserRole::AGRONOMIST,
                'languages_json' => ['en', 'sn'],
                'is_available_for_handoff' => true,
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'rep.mashcentral@farmserve.co.zw'],
            [
                'tenant_id' => $agroDealer->id,
                'name' => 'Tatenda Gono (Sales Rep - Mashonaland)',
                'password' => Hash::make('FarmServe@2026!'),
                'phone_encrypted' => '+263772100004',
                'whatsapp_number' => '+263772100004',
                'role' => UserRole::SALES_REP,
                'languages_json' => ['en', 'sn'],
                'availability_schedule_json' => [
                    'mon_fri' => ['08:00-12:00', '14:00-16:30'],
                    'sat' => ['08:00-12:00'],
                ],
                'is_available_for_handoff' => true,
            ]
        );

        $outletManagerUser = User::query()->updateOrCreate(
            ['email' => 'glendale.branch@farmserve.co.zw'],
            [
                'tenant_id' => $agroDealer->id,
                'name' => 'Blessing Nyoni (Glendale Outlet Manager)',
                'password' => Hash::make('FarmServe@2026!'),
                'phone_encrypted' => '+263772100005',
                'whatsapp_number' => '+263772100005',
                'role' => UserRole::OUTLET_MANAGER,
                'languages_json' => ['en', 'sn', 'nd'],
                'is_available_for_handoff' => true,
            ]
        );

        // 5. Outlets with PostGIS Coordinates across Zimbabwe
        $outletsSpec = [
            [
                'name' => 'FarmServe Glendale Agro-Branch (Mazowe)',
                'outlet_type' => 'retail_branch',
                'address' => 'Stand 42 Main Road, Glendale Business Centre, Mazowe',
                'ward_name' => 'Mazowe Ward 14 (Glendale)',
                'lat' => -17.3555,
                'lng' => 31.0672,
                'phone' => '+263772200101',
                'delivery_radius_km' => 45.0,
                'delivery_fee' => 8.00,
            ],
            [
                'name' => 'FarmServe Bindura Depot',
                'outlet_type' => 'distributor_depot',
                'address' => '18 Trojan Road Industrial Site, Bindura',
                'ward_name' => 'Bindura Communal Ward 8',
                'lat' => -17.3025,
                'lng' => 31.3315,
                'phone' => '+263772200102',
                'delivery_radius_km' => 60.0,
                'delivery_fee' => 10.00,
            ],
            [
                'name' => 'FarmServe Harare Southerton Flagship Depot',
                'outlet_type' => 'distributor_depot',
                'address' => '104 Highfield Road, Southerton, Harare',
                'ward_name' => 'Harare South Peri-Urban Ward 1',
                'lat' => -17.8680,
                'lng' => 31.0210,
                'phone' => '+263772200103',
                'delivery_radius_km' => 80.0,
                'delivery_fee' => 12.00,
            ],
            [
                'name' => 'FarmServe Marondera Agro-Shop',
                'outlet_type' => 'retail_branch',
                'address' => '12 Pine Street, Marondera',
                'ward_name' => 'Marondera Ward 6 (Wenimbi)',
                'lat' => -18.1850,
                'lng' => 31.5525,
                'phone' => '+263772200104',
                'delivery_radius_km' => 50.0,
                'delivery_fee' => 9.00,
            ],
            [
                'name' => 'FarmServe Chinhoyi Stockist',
                'outlet_type' => 'authorized_stockist',
                'address' => '55 Magamba Way, Chinhoyi',
                'ward_name' => 'Makonde Ward 9 (Chinhoyi)',
                'lat' => -17.3670,
                'lng' => 30.2010,
                'phone' => '+263772200105',
                'delivery_radius_km' => 50.0,
                'delivery_fee' => 10.00,
            ],
            [
                'name' => 'FarmServe Bulawayo Belmont Depot',
                'outlet_type' => 'distributor_depot',
                'address' => '88 Plumtree Road, Belmont, Bulawayo',
                'ward_name' => 'Umguza / Bulawayo Peri-Urban Ward 1',
                'lat' => -20.1575,
                'lng' => 28.5682,
                'phone' => '+263772200106',
                'delivery_radius_km' => 75.0,
                'delivery_fee' => 12.00,
            ],
        ];

        foreach ($outletsSpec as $idx => $spec) {
            $ward = Ward::query()->where('name', '=', $spec['ward_name'])->with('district')->first();
            if ($ward === null || $ward->district === null) {
                continue;
            }

            $outlet = Outlet::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $agroDealer->id,
                    'name' => $spec['name'],
                ],
                [
                    'outlet_type' => $spec['outlet_type'],
                    'address' => $spec['address'],
                    'province_id' => $ward->district->province_id,
                    'district_id' => $ward->district_id,
                    'ward_id' => $ward->id,
                    'latitude' => $spec['lat'],
                    'longitude' => $spec['lng'],
                    'operating_hours_json' => [
                        'mon_fri' => '07:30-17:00',
                        'sat' => '07:30-13:00',
                        'sun' => 'closed',
                    ],
                    'phone' => $spec['phone'],
                    'whatsapp_number' => $spec['phone'],
                    'delivery_radius_km' => $spec['delivery_radius_km'],
                    'delivery_fee_amount' => $spec['delivery_fee'],
                    'delivery_fee_currency' => 'USD',
                    'accepted_payments_json' => ['USD_CASH', 'ZIG_CASH', 'ECOCASH', 'PAYNOW', 'SWIPE'],
                    'is_active' => true,
                ]
            );

            if (DB::getDriverName() === 'pgsql') {
                DB::statement(
                    'UPDATE outlets SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$spec['lng'], $spec['lat'], $outlet->id]
                );
            }

            if ($idx === 0) {
                OutletUser::query()->updateOrCreate(
                    [
                        'outlet_id' => $outlet->id,
                        'user_id' => $outletManagerUser->id,
                    ],
                    [
                        'role' => 'outlet_manager',
                        'receives_whatsapp_alerts' => true,
                    ]
                );
            }
        }
    }
}
