<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\CurrencyCode;
use App\Enums\StockQuantityBand;
use App\Models\Crop;
use App\Models\FertiliserRecommendation;
use App\Models\Livestock;
use App\Models\Outlet;
use App\Models\OutletProductAuthorisation;
use App\Models\OutletStock;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductTarget;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

final class SampleProductCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $agroDealer = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->first();
        $manufacturer = Tenant::query()->where('slug', '=', 'zimcrop-chem')->first();

        if ($agroDealer === null) {
            return;
        }

        $maize = Crop::query()->where('code', '=', 'maize')->first();
        $tomato = Crop::query()->where('code', '=', 'tomatoes')->first();
        $soya = Crop::query()->where('code', '=', 'soya_beans')->first();
        $vegetables = Crop::query()->where('code', '=', 'vegetables')->first();
        $cattle = Livestock::query()->where('code', '=', 'cattle')->first();

        // 1. Seed Reference Label Document
        $labelDoc = ProductDocument::withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $agroDealer->id,
                'title' => 'Zimbabwe Approved Crop Protection & Nutrition Handbook 2026',
            ],
            [
                'document_type' => 'product_label',
                'language' => 'en',
                'file_path' => 'docs/zw-approved-handbook-2026.pdf',
                'file_hash' => hash('sha256', 'zw-approved-handbook-2026'),
                'ingestion_status' => 'completed',
                'chunks_count' => 18,
            ]
        );

        // 2. Seed Approved Products
        $productsCatalog = [
            [
                'sku' => 'ZIM-EMAMEC-5SG',
                'name' => 'BeltGuard Emamectin 5% SG',
                'brand_name' => 'ZimCrop',
                'category' => 'insecticide',
                'active_ingredient' => 'Emamectin benzoate 50 g/kg SG',
                'mode_of_action_group' => 'IRAC Group 6 (Avermectin)',
                'registration_number' => 'ZW-PPRI-2023-0412',
                'registration_expiry' => '2028-12-31',
                'hazard_colour_band' => 'amber',
                'agronomic_fit_rank_score' => 95.00,
                'differentiation_notes' => 'Translaminar action against Fall Armyworm & Tuta absoluta caterpillars; rainfast in 2 hours.',
                'pack_sizes_json' => [
                    ['size' => '100g', 'price_usd' => 6.50, 'price_zig' => 165.75],
                    ['size' => '250g', 'price_usd' => 14.50, 'price_zig' => 369.75],
                ],
                'ppe_requirements_json' => ['nitrile_gloves', 'respirator_mask', 'goggles', 'gumboots', 'long_overalls'],
                'safety_warnings_json' => [
                    'bee_warning' => 'Toxic to foraging bees; spray early morning or late afternoon when bees are not active.',
                    'water_warning' => 'Toxic to fish and aquatic organisms; do not wash sprayers near streams or dams.',
                    'mixing_order' => 'Half-fill knapsack with clean water, dissolve WDG/SG granules completely, then top up to 16 L.',
                ],
            ],
            [
                'sku' => 'ZIM-CHLORANT-200SC',
                'name' => 'CoragenPro Chlorantraniliprole 200 SC',
                'brand_name' => 'ZimCrop',
                'category' => 'insecticide',
                'active_ingredient' => 'Chlorantraniliprole 200 g/L SC',
                'mode_of_action_group' => 'IRAC Group 28 (Diamide)',
                'registration_number' => 'ZW-PPRI-2024-0118',
                'registration_expiry' => '2029-06-30',
                'hazard_colour_band' => 'green',
                'agronomic_fit_rank_score' => 98.00,
                'differentiation_notes' => 'Ideal rotation partner with IRAC Group 6 for Fall Armyworm, Stalk Borer, Bollworm, and Tuta absoluta; rapid feeding cessation.',
                'pack_sizes_json' => [
                    ['size' => '50ml', 'price_usd' => 11.00, 'price_zig' => 280.50],
                    ['size' => '200ml', 'price_usd' => 38.00, 'price_zig' => 969.00],
                ],
                'ppe_requirements_json' => ['nitrile_gloves', 'eye_protection', 'gumboots', 'long_overalls'],
                'safety_warnings_json' => [
                    'bee_warning' => 'Low bee toxicity once dried, but avoid direct spray on flowering weeds/crops.',
                    'water_warning' => 'Do not contaminate boreholes, wells, or rivers.',
                    'mixing_order' => 'Half-fill tank with water, agitate well, add SC suspension, and fill to 16 L.',
                ],
            ],
            [
                'sku' => 'ZIM-ACETAM-20SP',
                'name' => 'AphiShield Acetamiprid 20% SP',
                'brand_name' => 'ZimCrop',
                'category' => 'insecticide',
                'active_ingredient' => 'Acetamiprid 200 g/kg SP',
                'mode_of_action_group' => 'IRAC Group 4A (Neonicotinoid)',
                'registration_number' => 'ZW-PPRI-2022-0891',
                'registration_expiry' => '2028-03-31',
                'hazard_colour_band' => 'amber',
                'agronomic_fit_rank_score' => 88.00,
                'differentiation_notes' => 'Systemic control of aphids and whiteflies on vegetables, cotton, and tomatoes.',
                'pack_sizes_json' => [
                    ['size' => '100g', 'price_usd' => 5.00, 'price_zig' => 127.50],
                ],
                'ppe_requirements_json' => ['nitrile_gloves', 'mask', 'gumboots', 'overalls'],
                'safety_warnings_json' => [
                    'bee_warning' => 'HIGHLY TOXIC TO BEES. Never spray during crop flowering.',
                    'water_warning' => 'Keep at least 20m buffer from waterways.',
                ],
            ],
            [
                'sku' => 'ZIM-AZOXY-TEB-SC',
                'name' => 'AmistarTop-Z Azoxystrobin + Difenoconazole SC',
                'brand_name' => 'ZimCrop',
                'category' => 'fungicide',
                'active_ingredient' => 'Azoxystrobin 200 g/L + Difenoconazole 125 g/L SC',
                'mode_of_action_group' => 'FRAC Group 11 + Group 3 (Strobilurin + Triazole)',
                'registration_number' => 'ZW-PPRI-2023-0704',
                'registration_expiry' => '2028-11-30',
                'hazard_colour_band' => 'blue',
                'agronomic_fit_rank_score' => 94.00,
                'differentiation_notes' => 'Dual systemic and preventative fungicide for Maize Grey Leaf Spot (GLS), Rust, Soya Frogeye Leaf Spot, and Tomato Early/Late Blight.',
                'pack_sizes_json' => [
                    ['size' => '250ml', 'price_usd' => 16.00, 'price_zig' => 408.00],
                    ['size' => '1L', 'price_usd' => 56.00, 'price_zig' => 1428.00],
                ],
                'ppe_requirements_json' => ['nitrile_gloves', 'goggles', 'mask', 'gumboots', 'overalls'],
                'safety_warnings_json' => [
                    'water_warning' => 'Very toxic to aquatic life; do not dispose of rinse water near streams.',
                ],
            ],
            [
                'sku' => 'FERT-COMPOUND-D-50KG',
                'name' => 'ZFC Compound D Basal Fertiliser (7:14:7 + 8.5S)',
                'brand_name' => 'ZFC',
                'category' => 'fertiliser',
                'active_ingredient' => '7% N : 14% P2O5 : 7% K2O + 8.5% S',
                'mode_of_action_group' => 'NPK Basal Compound',
                'registration_number' => 'ZW-FSF-2021-0010',
                'registration_expiry' => '2030-12-31',
                'hazard_colour_band' => 'green',
                'agronomic_fit_rank_score' => 96.00,
                'differentiation_notes' => 'Standard Zimbabwean basal fertiliser for maize, wheat, sorghum, and upland cereals.',
                'pack_sizes_json' => [
                    ['size' => '50kg', 'price_usd' => 32.00, 'price_zig' => 816.00],
                    ['size' => '10kg', 'price_usd' => 7.50, 'price_zig' => 191.25],
                ],
                'ppe_requirements_json' => ['gloves', 'dust_mask'],
                'safety_warnings_json' => [
                    'handling' => 'Store in a dry shed off the floor; place 5cm away from seed at planting to avoid salt burn.',
                ],
            ],
            [
                'sku' => 'FERT-AN-345-50KG',
                'name' => 'Sable Ammonium Nitrate (AN 34.5% N) Top Dressing',
                'brand_name' => 'Sable',
                'category' => 'fertiliser',
                'active_ingredient' => '34.5% Total Nitrogen (Ammonium + Nitrate N)',
                'mode_of_action_group' => 'Nitrogen Top-Dressing',
                'registration_number' => 'ZW-FSF-2021-0015',
                'registration_expiry' => '2030-12-31',
                'hazard_colour_band' => 'amber',
                'agronomic_fit_rank_score' => 97.00,
                'differentiation_notes' => 'Rapid-acting Nitrogen top-dressing for V4-V8 vegetative maize, wheat, and leafy vegetables.',
                'pack_sizes_json' => [
                    ['size' => '50kg', 'price_usd' => 38.00, 'price_zig' => 969.00],
                    ['size' => '10kg', 'price_usd' => 8.50, 'price_zig' => 216.75],
                ],
                'ppe_requirements_json' => ['gloves'],
                'safety_warnings_json' => [
                    'handling' => 'Oxidiser — store away from fuel, oil, and heat. Do not apply onto wet foliage or bone-dry soil.',
                ],
            ],
            [
                'sku' => 'FERT-DOLOMITIC-LIME-50KG',
                'name' => 'Agricultural Dolomitic Lime (Ca + Mg)',
                'brand_name' => 'G&W Industrial Minerals',
                'category' => 'fertiliser',
                'active_ingredient' => 'Calcium Carbonate + Magnesium Carbonate (CCE > 85%)',
                'mode_of_action_group' => 'Soil pH Ameliorant & Ca/Mg Source',
                'registration_number' => 'ZW-FSF-2020-0042',
                'registration_expiry' => '2030-12-31',
                'hazard_colour_band' => 'green',
                'agronomic_fit_rank_score' => 92.00,
                'differentiation_notes' => 'Sweetens acidic Zimbabwean granite sandy soils (pH < 5.2 CaCl2) and unlocks phosphorus uptake.',
                'pack_sizes_json' => [
                    ['size' => '50kg', 'price_usd' => 6.00, 'price_zig' => 153.00],
                ],
                'ppe_requirements_json' => ['dust_mask', 'goggles', 'gloves'],
                'safety_warnings_json' => [
                    'handling' => 'Avoid inhaling dust; do not mix directly in the same hole with Ammonium Nitrate at the same time.',
                ],
            ],
            [
                'sku' => 'VET-BUPARVA-50ML',
                'name' => 'BupaVet Buparvaquone 50 mg/ml Injection',
                'brand_name' => 'VetCare ZW',
                'category' => 'animal_health',
                'active_ingredient' => 'Buparvaquone 50 mg/ml',
                'mode_of_action_group' => 'Antiprotozoal (Theilericide)',
                'registration_number' => 'ZW-MCAZ-VET-2022-091',
                'registration_expiry' => '2028-12-31',
                'hazard_colour_band' => 'amber',
                'agronomic_fit_rank_score' => 95.00,
                'differentiation_notes' => 'Specific intramuscular treatment for cattle Theileriosis (January Disease) alongside tick control.',
                'pack_sizes_json' => [
                    ['size' => '50ml', 'price_usd' => 24.00, 'price_zig' => 612.00],
                ],
                'ppe_requirements_json' => ['gloves'],
                'safety_warnings_json' => [
                    'withholding' => 'Meat withholding period: 42 days. Milk withholding period: 48 hours. Consult a Veterinary Officer immediately.',
                ],
            ],
        ];

        $createdProductsBySku = [];
        foreach ($productsCatalog as $item) {
            $product = Product::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $agroDealer->id,
                    'sku' => $item['sku'],
                ],
                array_merge($item, [
                    'manufacturer_tenant_id' => $manufacturer?->id,
                    'is_active' => true,
                ])
            );
            $createdProductsBySku[$item['sku']] = $product;
        }

        // 3. Seed Deterministic ProductTargets (Crop x Target x Dose per 16L Knapsack & per Ha x PHI/REI)
        $targetsSpec = [
            [
                'sku' => 'ZIM-EMAMEC-5SG',
                'crop_id' => $maize?->id,
                'livestock_id' => null,
                'target_type' => 'pest',
                'target_code' => 'fall_armyworm',
                'target_name_en' => 'Fall Armyworm (Spodoptera frugiperda)',
                'target_name_sn' => 'Gonye remunhava / Honye',
                'target_name_nd' => 'Amacimbi / Impethu yomumbu',
                'knapsack_val' => 8.00,
                'knapsack_unit' => 'g',
                'ha_val' => 150.00,
                'ha_unit' => 'g',
                'water_l' => 250.00,
                'mixing' => 'Half-fill 16 L knapsack with clean water -> dissolve 8 g BeltGuard SG -> top up to 16 L and agitate.',
                'timing' => 'Direct nozzle into the maize funnel (whorl) early morning (06:00-09:00) or late afternoon (16:00-18:00) when caterpillars emerge.',
                'interval_days' => 10,
                'max_apps' => 2,
                'phi_days' => 14,
                'rei_hours' => 12,
                'page' => 12,
            ],
            [
                'sku' => 'ZIM-CHLORANT-200SC',
                'crop_id' => $maize?->id,
                'livestock_id' => null,
                'target_type' => 'pest',
                'target_code' => 'fall_armyworm',
                'target_name_en' => 'Fall Armyworm (Spodoptera frugiperda)',
                'target_name_sn' => 'Gonye remunhava / Honye',
                'target_name_nd' => 'Amacimbi / Impethu yomumbu',
                'knapsack_val' => 10.00,
                'knapsack_unit' => 'ml',
                'ha_val' => 150.00,
                'ha_unit' => 'ml',
                'water_l' => 250.00,
                'mixing' => 'Half-fill 16 L knapsack -> add 10 ml CoragenPro 200 SC -> stir and top up to 16 L.',
                'timing' => 'Apply at egg hatch or early instar window-pane damage directly into the whorl. Rotate with IRAC Group 6.',
                'interval_days' => 14,
                'max_apps' => 2,
                'phi_days' => 14,
                'rei_hours' => 12,
                'page' => 15,
            ],
            [
                'sku' => 'ZIM-CHLORANT-200SC',
                'crop_id' => $maize?->id,
                'livestock_id' => null,
                'target_type' => 'pest',
                'target_code' => 'maize_stalk_borer',
                'target_name_en' => 'Maize Stalk Borer (Busseola fusca)',
                'target_name_sn' => 'Chiburi / Gonye redzinde',
                'target_name_nd' => 'Isibungu somumbu',
                'knapsack_val' => 10.00,
                'knapsack_unit' => 'ml',
                'ha_val' => 150.00,
                'ha_unit' => 'ml',
                'water_l' => 250.00,
                'mixing' => 'Half-fill 16 L knapsack -> add 10 ml CoragenPro 200 SC -> top up to 16 L.',
                'timing' => 'Apply into the funnel at 3-5 weeks after emergence before larvae bore into the stem.',
                'interval_days' => 14,
                'max_apps' => 2,
                'phi_days' => 14,
                'rei_hours' => 12,
                'page' => 16,
            ],
            [
                'sku' => 'ZIM-CHLORANT-200SC',
                'crop_id' => $tomato?->id,
                'livestock_id' => null,
                'target_type' => 'pest',
                'target_code' => 'tuta_absoluta',
                'target_name_en' => 'Tomato Leaf Miner (Tuta absoluta)',
                'target_name_sn' => 'Tuta /ukonye hwemadomasi',
                'target_name_nd' => 'Impethu yamatamatisi (Tuta)',
                'knapsack_val' => 12.00,
                'knapsack_unit' => 'ml',
                'ha_val' => 200.00,
                'ha_unit' => 'ml',
                'water_l' => 300.00,
                'mixing' => 'Half-fill 16 L knapsack -> add 12 ml CoragenPro 200 SC -> top up to 16 L.',
                'timing' => 'Spray both upper and lower leaf surfaces at first sign of blotch mines or pheromone trap catch.',
                'interval_days' => 10,
                'max_apps' => 2,
                'phi_days' => 3,
                'rei_hours' => 12,
                'page' => 22,
            ],
            [
                'sku' => 'ZIM-ACETAM-20SP',
                'crop_id' => $vegetables?->id,
                'livestock_id' => null,
                'target_type' => 'pest',
                'target_code' => 'aphids',
                'target_name_en' => 'Aphids (Brevicoryne brassicae / Myzus persicae)',
                'target_name_sn' => 'inda dzemuriwo (Aphids)',
                'target_name_nd' => 'Inda zemibhida (Aphids)',
                'knapsack_val' => 8.00,
                'knapsack_unit' => 'g',
                'ha_val' => 125.00,
                'ha_unit' => 'g',
                'water_l' => 250.00,
                'mixing' => 'Dissolve 8 g AphiShield 20 SP in 16 L clean water with a non-ionic wetter for waxy brassica leaves.',
                'timing' => 'Ensure thorough coverage under leaves and in leaf curls.',
                'interval_days' => 10,
                'max_apps' => 2,
                'phi_days' => 7,
                'rei_hours' => 12,
                'page' => 28,
            ],
            [
                'sku' => 'ZIM-AZOXY-TEB-SC',
                'crop_id' => $maize?->id,
                'livestock_id' => null,
                'target_type' => 'disease',
                'target_code' => 'grey_leaf_spot',
                'target_name_en' => 'Grey Leaf Spot (Cercospora zeae-maydis)',
                'target_name_sn' => 'Chitaka chemashizha (Grey Leaf Spot)',
                'target_name_nd' => 'Amabala empunga emahlamvwini',
                'knapsack_val' => 30.00,
                'knapsack_unit' => 'ml',
                'ha_val' => 500.00,
                'ha_unit' => 'ml',
                'water_l' => 250.00,
                'mixing' => 'Half-fill 16 L knapsack -> add 30 ml AmistarTop-Z SC -> agitate and top up to 16 L.',
                'timing' => 'Spray at first rectangular tan lesions on lower leaves before ear-leaf infection.',
                'interval_days' => 14,
                'max_apps' => 2,
                'phi_days' => 21,
                'rei_hours' => 12,
                'page' => 34,
            ],
            [
                'sku' => 'ZIM-AZOXY-TEB-SC',
                'crop_id' => $tomato?->id,
                'livestock_id' => null,
                'target_type' => 'disease',
                'target_code' => 'early_and_late_blight',
                'target_name_en' => 'Early & Late Blight (Alternaria solani / Phytophthora infestans)',
                'target_name_sn' => 'Chando / Blight yemadomasi',
                'target_name_nd' => 'I-Blight yamatamatisi',
                'knapsack_val' => 25.00,
                'knapsack_unit' => 'ml',
                'ha_val' => 500.00,
                'ha_unit' => 'ml',
                'water_l' => 300.00,
                'mixing' => 'Half-fill 16 L knapsack -> add 25 ml AmistarTop-Z SC -> top up to 16 L.',
                'timing' => 'Apply preventatively during humid/wet weather or at first concentric target-ring spots.',
                'interval_days' => 10,
                'max_apps' => 3,
                'phi_days' => 7,
                'rei_hours' => 12,
                'page' => 36,
            ],
            [
                'sku' => 'ZIM-AZOXY-TEB-SC',
                'crop_id' => $soya?->id,
                'livestock_id' => null,
                'target_type' => 'disease',
                'target_code' => 'frogeye_leaf_spot',
                'target_name_en' => 'Frogeye Leaf Spot & Soya Rust',
                'target_name_sn' => 'Ziso redafi / Ngura yesoya',
                'target_name_nd' => 'ihlo lexoxo / Ukugwala kwesoya',
                'knapsack_val' => 30.00,
                'knapsack_unit' => 'ml',
                'ha_val' => 500.00,
                'ha_unit' => 'ml',
                'water_l' => 250.00,
                'mixing' => 'Half-fill 16 L knapsack -> add 30 ml AmistarTop-Z SC -> top up to 16 L.',
                'timing' => 'Apply at R1-R3 flowering to early pod set.',
                'interval_days' => 14,
                'max_apps' => 2,
                'phi_days' => 21,
                'rei_hours' => 12,
                'page' => 38,
            ],
            [
                'sku' => 'VET-BUPARVA-50ML',
                'crop_id' => null,
                'livestock_id' => $cattle?->id,
                'target_type' => 'livestock_disease',
                'target_code' => 'theileriosis_january_disease',
                'target_name_en' => 'Theileriosis / January Disease (Corridor Disease)',
                'target_name_sn' => 'Chirwere cheJanuary Disease (Chigwere chehumba)',
                'target_name_nd' => 'Umkhuhlane weJanuary Disease (Amankwahla)',
                'knapsack_val' => 2.50,
                'knapsack_unit' => 'ml',
                'ha_val' => 20.00,
                'ha_unit' => 'ml',
                'water_l' => 0.00,
                'mixing' => 'Ready-to-use sterile intramuscular injection: 1 ml per 20 kg live bodyweight (e.g., 20 ml for a 400 kg beast) into the neck muscle.',
                'timing' => 'Administer at earliest sign of swollen lymph nodes, fever (>40C), and corneal opacity; plunge/spray dip cattle 5-5-4 during peak rain.',
                'interval_days' => 2,
                'max_apps' => 2,
                'phi_days' => 42,
                'rei_hours' => 0,
                'page' => 52,
            ],
        ];

        foreach ($targetsSpec as $t) {
            $product = $createdProductsBySku[$t['sku']] ?? null;
            if ($product === null) {
                continue;
            }

            ProductTarget::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $agroDealer->id,
                    'product_id' => $product->id,
                    'crop_id' => $t['crop_id'],
                    'livestock_id' => $t['livestock_id'],
                    'target_code' => $t['target_code'],
                ],
                [
                    'target_type' => $t['target_type'],
                    'target_name_en' => $t['target_name_en'],
                    'target_name_sn' => $t['target_name_sn'],
                    'target_name_nd' => $t['target_name_nd'],
                    'dose_per_16l_knapsack_value' => $t['knapsack_val'],
                    'dose_per_16l_knapsack_unit' => $t['knapsack_unit'],
                    'dose_per_ha_value' => $t['ha_val'],
                    'dose_per_ha_unit' => $t['ha_unit'],
                    'water_volume_per_ha_litres' => $t['water_l'],
                    'mixing_order' => $t['mixing'],
                    'application_timing_notes' => $t['timing'],
                    'reapplication_interval_days' => $t['interval_days'],
                    'max_applications_per_season' => $t['max_apps'],
                    'phi_days' => $t['phi_days'],
                    'rei_hours' => $t['rei_hours'],
                    'source_document_id' => $labelDoc->id,
                    'source_page_number' => $t['page'],
                ]
            );
        }

        // 4. Seed Deterministic FertiliserRecommendations (Maize & Tomatoes)
        if ($maize !== null) {
            FertiliserRecommendation::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $agroDealer->id,
                    'crop_id' => $maize->id,
                    'nutrient_addressed' => 'N',
                    'growth_stage' => 'v4_v6_topdress',
                ],
                [
                    'product_id' => $createdProductsBySku['FERT-AN-345-50KG']->id ?? null,
                    'natural_region' => 'ALL',
                    'soil_type' => 'ALL',
                    'fertiliser_type_name' => 'Ammonium Nitrate (AN 34.5% N)',
                    'rate_kg_per_ha' => 250.00,
                    'rate_grams_per_plant' => 5.00,
                    'rate_grams_per_pfumvudza_station' => 10.00,
                    'local_measure_equivalent' => '1 level Nr. 5 cup (or 1 heaped beer bottle cap = 5g per plant; 2 caps = 10g per 2-plant Pfumvudza hole)',
                    'placement_method' => 'side_dress',
                    'timing_notes' => 'Apply at 4-6 weeks after emergence (knee-high / V6). On sandy soils (NR III-V), split into two applications at 4 weeks and 7 weeks.',
                    'precautions_notes' => 'Apply only when soil is moist. Place 5-7 cm away from the maize stem base; never drop granules into the funnel or onto wet leaves.',
                    'source_document_id' => $labelDoc->id,
                ]
            );

            FertiliserRecommendation::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $agroDealer->id,
                    'crop_id' => $maize->id,
                    'nutrient_addressed' => 'P',
                    'growth_stage' => 'basal_planting',
                ],
                [
                    'product_id' => $createdProductsBySku['FERT-COMPOUND-D-50KG']->id ?? null,
                    'natural_region' => 'ALL',
                    'soil_type' => 'ALL',
                    'fertiliser_type_name' => 'Compound D Basal (7:14:7 + 8.5% S)',
                    'rate_kg_per_ha' => 300.00,
                    'rate_grams_per_plant' => 6.00,
                    'rate_grams_per_pfumvudza_station' => 10.00,
                    'local_measure_equivalent' => '1 level Nr. 8 cup (10g per Pfumvudza planting hole) covered with 2 cm soil before placing seed',
                    'placement_method' => 'dollop_station',
                    'timing_notes' => 'Apply in planting furrow or Pfumvudza planting basin before emergence. If purple leaves appear post-emergence, side-dress soluble P foliar + check soil pH.',
                    'precautions_notes' => 'Cover basal fertiliser lightly with soil so seed does not touch raw granules. Test soil pH; if pH < 5.2 (CaCl2), apply Dolomitic Lime.',
                    'source_document_id' => $labelDoc->id,
                ]
            );

            FertiliserRecommendation::withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $agroDealer->id,
                    'crop_id' => $maize->id,
                    'nutrient_addressed' => 'Ca_Lime',
                    'growth_stage' => 'basal_planting',
                ],
                [
                    'product_id' => $createdProductsBySku['FERT-DOLOMITIC-LIME-50KG']->id ?? null,
                    'natural_region' => 'ALL',
                    'soil_type' => 'sand',
                    'fertiliser_type_name' => 'Agricultural Dolomitic Lime',
                    'rate_kg_per_ha' => 500.00,
                    'rate_grams_per_plant' => 5.00,
                    'rate_grams_per_pfumvudza_station' => 10.00,
                    'local_measure_equivalent' => '1 level Nr. 5 cup (5g) to Nr. 8 cup (10g) per Pfumvudza planting station mixed into soil at holing',
                    'placement_method' => 'dollop_station',
                    'timing_notes' => 'Incorporate into Pfumvudza holes during winter/early holing or broadcast before ploughing.',
                    'precautions_notes' => 'Never mix lime directly with Ammonium Nitrate or Urea in the same application.',
                    'source_document_id' => $labelDoc->id,
                ]
            );
        }

        // 5. Seed Outlet Authorisations & Live Stock in both USD and ZiG
        $outlets = Outlet::withoutGlobalScopes()
            ->where('tenant_id', '=', $agroDealer->id)
            ->get();

        foreach ($outlets as $outlet) {
            foreach ($createdProductsBySku as $product) {
                OutletProductAuthorisation::withoutGlobalScopes()->updateOrCreate(
                    [
                        'outlet_id' => $outlet->id,
                        'product_id' => $product->id,
                    ],
                    [
                        'tenant_id' => $agroDealer->id,
                        'authorised_by_tenant_id' => $manufacturer?->id,
                        'is_authorised' => true,
                    ]
                );

                $packs = (array) ($product->pack_sizes_json ?? []);
                foreach ($packs as $pack) {
                    $size = (string) $pack['size'];
                    $priceUsd = (float) $pack['price_usd'];
                    $priceZig = (float) $pack['price_zig'];

                    OutletStock::withoutGlobalScopes()->updateOrCreate(
                        [
                            'outlet_id' => $outlet->id,
                            'product_id' => $product->id,
                            'pack_size' => $size,
                            'currency' => CurrencyCode::USD->value,
                        ],
                        [
                            'tenant_id' => $agroDealer->id,
                            'quantity_band' => StockQuantityBand::IN_STOCK,
                            'exact_quantity' => 45,
                            'price' => $priceUsd,
                            'source' => 'outlet_portal',
                            'last_updated_at' => now()->subHours(4),
                        ]
                    );

                    OutletStock::withoutGlobalScopes()->updateOrCreate(
                        [
                            'outlet_id' => $outlet->id,
                            'product_id' => $product->id,
                            'pack_size' => $size,
                            'currency' => CurrencyCode::ZIG->value,
                        ],
                        [
                            'tenant_id' => $agroDealer->id,
                            'quantity_band' => StockQuantityBand::IN_STOCK,
                            'exact_quantity' => 45,
                            'price' => $priceZig,
                            'source' => 'outlet_portal',
                            'last_updated_at' => now()->subHours(4),
                        ]
                    );
                }
            }
        }
    }
}
