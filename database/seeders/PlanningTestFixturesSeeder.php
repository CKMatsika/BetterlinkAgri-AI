<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Benchmark;
use App\Models\BenchmarkSource;
use App\Models\EnterpriseQuestionBank;
use App\Models\MarketPrice;
use App\Models\PlanEnterprise;
use Illuminate\Database\Seeder;

/**
 * Seeds P1a Enterprise Question Banks and sample unverified test-fixture benchmarks & market prices.
 * All sample benchmark/price rows are strictly marked status='unverified' and sourced as
 * "test fixture, not agronomic data".
 */
final class PlanningTestFixturesSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new \RuntimeException('PlanningTestFixturesSeeder cannot run in production.');
        }

        $enterprises = [
            ['code' => 'broilers', 'name_en' => 'Broiler Chickens', 'name_sn' => 'Huku dzenyama (Broilers)', 'name_nd' => 'Inkukhu zenyama (Broilers)', 'category' => 'livestock', 'default_unit' => 'birds'],
            ['code' => 'maize', 'name_en' => 'Maize Grain', 'name_sn' => 'Chibage', 'name_nd' => 'Umumbu', 'category' => 'grain', 'default_unit' => 'hectares'],
            ['code' => 'wheat', 'name_en' => 'Winter Wheat', 'name_sn' => 'Gorosi rechirimo', 'name_nd' => 'Ingqoloyi', 'category' => 'grain', 'default_unit' => 'hectares'],
            ['code' => 'tobacco', 'name_en' => 'Flue-Cured Tobacco', 'name_sn' => 'Fodya', 'name_nd' => 'Igwayi', 'category' => 'cash_crop', 'default_unit' => 'hectares'],
            ['code' => 'dairy', 'name_en' => 'Small-Scale Dairy', 'name_sn' => 'Mombe dzemukaka', 'name_nd' => 'Inkomo zochago', 'category' => 'livestock', 'default_unit' => 'cows'],
        ];

        $enterpriseModels = [];
        foreach ($enterprises as $e) {
            $enterpriseModels[$e['code']] = PlanEnterprise::query()->updateOrCreate(
                ['code' => $e['code']],
                $e
            );
        }

        // Enterprise Question Banks: Broilers
        $broilerQuestions = [
            [
                'question_key' => 'broiler_birds_per_batch',
                'question_order' => 1,
                'question_text_en' => 'How many broiler chicks do you plan to raise per batch?',
                'question_text_sn' => 'Muri kuda kuchengeta nhiyo dzehuku ngani pabatch imwe chete?',
                'question_text_nd' => 'Lifuna ukufuya amatsiyane amangaki ngesikhathi sinye?',
                'options_json' => [
                    ['id' => '100', 'title' => '100 birds'],
                    ['id' => '500', 'title' => '500 birds'],
                    ['id' => '1000', 'title' => '1,000 birds'],
                ],
                'validation_rules_json' => null,
            ],
            [
                'question_key' => 'broiler_batches_per_year',
                'question_order' => 2,
                'question_text_en' => 'How many batches per year do you plan to run?',
                'question_text_sn' => 'Muri kuronga kuita mabatches mangani pagore?',
                'question_text_nd' => 'Lihlela ukufuya kangaki ngomnyaka?',
                'options_json' => [
                    ['id' => '4', 'title' => '4 batches/yr'],
                    ['id' => '6', 'title' => '6 batches/yr'],
                    ['id' => '8', 'title' => '8 batches/yr (tight)'],
                ],
                'validation_rules_json' => ['max_cycles_per_year' => 8],
            ],
            [
                'question_key' => 'broiler_market_channel',
                'question_order' => 3,
                'question_text_en' => 'Who will buy your finished birds?',
                'question_text_sn' => 'Ndiani achatenga huku dzenyu kana dzakura?',
                'question_text_nd' => 'Ngubani ozathenga inkukhu zenu sezikulile?',
                'options_json' => [
                    ['id' => 'live_local', 'title' => 'Local live sales'],
                    ['id' => 'dressed', 'title' => 'Dressed / frozen'],
                    ['id' => 'abattoir', 'title' => 'Abattoir / contract'],
                ],
                'validation_rules_json' => null,
            ],
        ];

        foreach ($broilerQuestions as $q) {
            EnterpriseQuestionBank::query()->updateOrCreate(
                [
                    'enterprise_id' => $enterpriseModels['broilers']->id,
                    'question_key' => $q['question_key'],
                ],
                $q
            );
        }

        // Enterprise Question Banks: Winter Wheat (checks irrigation)
        EnterpriseQuestionBank::query()->updateOrCreate(
            [
                'enterprise_id' => $enterpriseModels['wheat']->id,
                'question_key' => 'wheat_irrigation_available',
            ],
            [
                'question_order' => 1,
                'question_text_en' => 'Do you have reliable irrigation (centre pivot, sprinkler, or drip) for winter wheat?',
                'question_text_sn' => 'Mune mvura yekudiridzira yakavimbika here yegorosi rechirimo?',
                'question_text_nd' => 'Lilamanzi okuthelela athembekileyo yini engqoloyini yebusika?',
                'options_json' => [
                    ['id' => 'yes', 'title' => 'Yes, irrigated'],
                    ['id' => 'no', 'title' => 'No irrigation'],
                ],
                'validation_rules_json' => ['requires_irrigation' => true],
            ]
        );

        // Enterprise Question Banks: Maize
        EnterpriseQuestionBank::query()->updateOrCreate(
            [
                'enterprise_id' => $enterpriseModels['maize']->id,
                'question_key' => 'maize_farming_method',
            ],
            [
                'question_order' => 1,
                'question_text_en' => 'Which planting method will you use for maize?',
                'question_text_sn' => 'Muchashandisa nzira ipi pakudyara chibage?',
                'question_text_nd' => 'Lizasebenzisa yiphi indlela yokulima umumbu?',
                'options_json' => [
                    ['id' => 'pfumvudza', 'title' => 'Pfumvudza / Intwasa'],
                    ['id' => 'conventional', 'title' => 'Conventional tillage'],
                ],
                'validation_rules_json' => null,
            ]
        );

        // Benchmark Source explicitly labelled "test fixture, not agronomic data"
        $fixtureSource = BenchmarkSource::query()->updateOrCreate(
            ['title' => 'test fixture, not agronomic data'],
            [
                'publisher' => 'BetterlinkAgr Test Fixture Suite',
                'publication_year' => '2026',
                'document_reference' => 'FIXTURE-ONLY-UNVERIFIED',
            ]
        );

        // Sample Unverified Benchmarks (status = 'unverified')
        $fixtureBenchmarks = [
            ['broilers', 'ALL', 'mortality_pct', 'decimal_2', 500, '%', null],
            ['broilers', 'ALL', 'day_old_chick_cost_minor', 'integer_minor', 110, 'bird', 'USD'],
            ['broilers', 'ALL', 'feed_cost_per_bird_minor', 'integer_minor', 340, 'bird', 'USD'],
            ['broilers', 'ALL', 'vet_brooding_per_bird_minor', 'integer_minor', 65, 'bird', 'USD'],
            ['broilers', 'ALL', 'housing_equip_capex_minor', 'integer_minor', 50000, 'batch', 'USD'],
            ['maize', 'ALL', 'yield_tonnes_per_ha_scaled', 'decimal_2', 450, 't/ha', null],
            ['maize', 'ALL', 'inputs_cost_per_ha_minor', 'integer_minor', 65000, 'ha', 'USD'],
            ['maize', 'ALL', 'labour_tillage_per_ha_minor', 'integer_minor', 32000, 'ha', 'USD'],
        ];

        foreach ($fixtureBenchmarks as [$ent, $nr, $key, $type, $val, $unit, $curr]) {
            Benchmark::query()->updateOrCreate(
                [
                    'enterprise_code' => $ent,
                    'natural_region' => $nr,
                    'metric_key' => $key,
                ],
                [
                    'value_type' => $type,
                    'value_scaled' => $val,
                    'unit' => $unit,
                    'currency' => $curr,
                    'source_id' => $fixtureSource->id,
                    'effective_date' => now()->toDateString(),
                    'status' => 'unverified',
                ]
            );
        }

        // Sample Unverified Market Output Prices (status = 'unverified')
        $fixturePrices = [
            ['live_broiler', 'Live Broiler Bird (1.8kg-2.0kg)', 'National', 'Grade A', 'bird', 650, 'USD'],
            ['maize_tonne', 'Maize Grain (per Metric Tonne)', 'National', 'Grade A', 'tonne', 35000, 'USD'],
            ['wheat_tonne', 'Winter Wheat (per Metric Tonne)', 'National', 'Standard', 'tonne', 42000, 'USD'],
        ];

        foreach ($fixturePrices as [$code, $name, $region, $grade, $unit, $priceMinor, $curr]) {
            MarketPrice::query()->updateOrCreate(
                [
                    'commodity_code' => $code,
                    'region_or_district' => $region,
                    'grade' => $grade,
                    'currency' => $curr,
                ],
                [
                    'commodity_name' => $name,
                    'unit' => $unit,
                    'price_minor' => $priceMinor,
                    'source' => 'test fixture, not agronomic data',
                    'effective_date' => now()->toDateString(),
                    'status' => 'unverified',
                ]
            );
        }
    }
}
