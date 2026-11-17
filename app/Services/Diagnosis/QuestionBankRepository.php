<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\Enums\DiagnosisCategory;
use App\Enums\LanguageCode;

/**
 * Category-specific adaptive question bank for Zimbabwean crops and livestock.
 * Each question includes localized text (en, sn, nd) and numbered/quick-reply options
 * that boost or penalize specific candidate codes.
 */
final class QuestionBankRepository
{
    /**
     * Return mandatory context questions when crop, growth stage, or field size/Pfumvudza plot count is unknown.
     *
     * @return array<string, array<string, mixed>>
     */
    public function mandatoryContextQuestions(): array
    {
        return [
            'field_size_or_pfumvudza' => [
                'text' => [
                    'en' => 'What is the size of the affected field or how many Pfumvudza plots are you treating?',
                    'sn' => 'Munda wenyu wakakura zvakadii kana kuti mune minda mingani yePfumvudza?',
                    'nd' => 'Insimu yenu ingakanani kumbe lilendima zingaki zePfumvudza/Intwasa?',
                ],
                'options' => [
                    ['id' => 'pfumvudza_1', 'title' => '1 Pfumvudza plot', 'field_size_ha' => 0.0624, 'pfumvudza_plots' => 1],
                    ['id' => 'pfumvudza_3', 'title' => '3 Pfumvudza plots', 'field_size_ha' => 0.1872, 'pfumvudza_plots' => 3],
                    ['id' => 'ha_0_5', 'title' => '0.5 Hectare', 'field_size_ha' => 0.50, 'pfumvudza_plots' => 0],
                    ['id' => 'ha_1_0', 'title' => '1 Hectare', 'field_size_ha' => 1.00, 'pfumvudza_plots' => 0],
                ],
            ],
            'crop_growth_stage' => [
                'text' => [
                    'en' => 'What growth stage is the crop currently at?',
                    'sn' => 'Chirimwa chenyu chave padanho ripi rekukura?',
                    'nd' => 'Isilimo senu sesikuzinga bani lokukhula?',
                ],
                'options' => [
                    ['id' => 'stage_emerged', 'title' => 'Seedling (1-3 wks)'],
                    ['id' => 'stage_vegetative', 'title' => 'Knee-high (V4-V8)'],
                    ['id' => 'stage_flowering', 'title' => 'Tasseling/Flowering'],
                    ['id' => 'stage_fruiting', 'title' => 'Cob/Pod/Fruit fill'],
                ],
            ],
        ];
    }

    /**
     * Return discriminative questions by DiagnosisCategory.
     *
     * @return list<array{
     *   key: string,
     *   discriminates: list<string>,
     *   text: array{en: string, sn: string, nd: string},
     *   options: list<array{id: string, title: string, boosts_candidate?: string, confidence_delta?: float, triggers_escalation?: bool}>
     * }>
     */
    public function questionsForCategory(DiagnosisCategory $category): array
    {
        return match ($category) {
            DiagnosisCategory::PEST => [
                [
                    'key' => 'insect_head_and_tail_markings',
                    'discriminates' => ['fall_armyworm', 'maize_stalk_borer', 'bollworm'],
                    'text' => [
                        'en' => 'Where is the damage and what does the caterpillar look like inside the funnel?',
                        'sn' => 'Gonye riri mumwoyo wembesa rakaita sei uye tsvina yaro yakaita sei?',
                        'nd' => 'Isibungu esiphakathi komumbu sinjani?',
                    ],
                    'options' => [
                        [
                            'id' => 'faw_signs',
                            'title' => '4 tail dots + frass',
                            'boosts_candidate' => 'fall_armyworm',
                            'confidence_delta' => 0.16,
                        ],
                        [
                            'id' => 'borer_signs',
                            'title' => 'Pinkish +stem holes',
                            'boosts_candidate' => 'maize_stalk_borer',
                            'confidence_delta' => 0.18,
                        ],
                        [
                            'id' => 'bollworm_signs',
                            'title' => 'On cob/fruit/pod',
                            'boosts_candidate' => 'bollworm',
                            'confidence_delta' => 0.16,
                        ],
                    ],
                ],
                [
                    'key' => 'percentage_plants_affected',
                    'discriminates' => ['fall_armyworm', 'maize_stalk_borer', 'tuta_absoluta', 'aphids'],
                    'text' => [
                        'en' => 'Roughly what percentage of plants in the field are showing this pest damage?',
                        'sn' => 'Chikamu chakadii chemiti mumunda chabatwa nedambudziko iri?',
                        'nd' => 'Zingaki izilimo ensimini ezilesi sibungu?',
                    ],
                    'options' => [
                        [
                            'id' => 'pct_under_10',
                            'title' => 'Under 10% (few)',
                            'confidence_delta' => 0.08,
                        ],
                        [
                            'id' => 'pct_20_50',
                            'title' => '20% to 50% (many)',
                            'confidence_delta' => 0.12,
                        ],
                        [
                            'id' => 'pct_over_50',
                            'title' => 'Over 50% (outbreak)',
                            'confidence_delta' => 0.12,
                            'triggers_escalation' => false,
                        ],
                    ],
                ],
                [
                    'key' => 'leaf_mine_or_sucking_pattern',
                    'discriminates' => ['tuta_absoluta', 'red_spider_mite', 'aphids', 'whitefly'],
                    'text' => [
                        'en' => 'What sign do you see under the leaf or when you shake the plant?',
                        'sn' => 'Chii chamunoona pasi peshizha kana mukazunza muti?',
                        'nd' => 'Libonani ngaphansi kwehlamvu nxa linyikinya isilimo?',
                    ],
                    'options' => [
                        [
                            'id' => 'silvery_blotches',
                            'title' => 'Blotch mines + holes',
                            'boosts_candidate' => 'tuta_absoluta',
                            'confidence_delta' => 0.18,
                        ],
                        [
                            'id' => 'red_mites_web',
                            'title' => 'Tiny red mites/webs',
                            'boosts_candidate' => 'red_spider_mite',
                            'confidence_delta' => 0.18,
                        ],
                        [
                            'id' => 'sticky_aphids',
                            'title' => 'Sticky lice/aphids',
                            'boosts_candidate' => 'aphids',
                            'confidence_delta' => 0.18,
                        ],
                    ],
                ],
                [
                    'key' => 'recent_chemical_sprays',
                    'discriminates' => ['fall_armyworm', 'tuta_absoluta'],
                    'text' => [
                        'en' => 'Have you sprayed any insecticide in the last 14 days?',
                        'sn' => 'Pane mushonga wamafiritisa here mumazuva gumi nemana apfuura?',
                        'nd' => 'ukhona umuthi eliwufafaze ensukwini ezingu-14 ezedluleyo?',
                    ],
                    'options' => [
                        ['id' => 'no_spray', 'title' => 'No sprays yet', 'confidence_delta' => 0.08],
                        ['id' => 'sprayed_pyrethroid', 'title' => 'Yes (Karate/Lambda)', 'confidence_delta' => 0.10],
                        ['id' => 'sprayed_other', 'title' => 'Yes (Other product)', 'confidence_delta' => 0.08],
                    ],
                ],
            ],

            DiagnosisCategory::DISEASE => [
                [
                    'key' => 'lesion_shape_and_powder',
                    'discriminates' => ['grey_leaf_spot', 'maize_streak_virus', 'maize_rust', 'early_and_late_blight'],
                    'text' => [
                        'en' => 'How do the spots or streaks look up close on the leaf?',
                        'sn' => 'Mavara kana mitsetse iri pashizha yakaita sei?',
                        'nd' => 'Amabala kumbe imizila esehlamvwini injani?',
                    ],
                    'options' => [
                        [
                            'id' => 'rectangular_tan',
                            'title' => 'Rectangular grey/tan',
                            'boosts_candidate' => 'grey_leaf_spot',
                            'confidence_delta' => 0.18,
                        ],
                        [
                            'id' => 'broken_yellow_streaks',
                            'title' => 'Broken yellow dashes',
                            'boosts_candidate' => 'maize_streak_virus',
                            'confidence_delta' => 0.18,
                        ],
                        [
                            'id' => 'bullseye_or_water_soaked',
                            'title' => 'Target rings / blotch',
                            'boosts_candidate' => 'early_and_late_blight',
                            'confidence_delta' => 0.18,
                        ],
                    ],
                ],
                [
                    'key' => 'weather_and_spread_pattern',
                    'discriminates' => ['grey_leaf_spot', 'early_and_late_blight', 'frogeye_leaf_spot'],
                    'text' => [
                        'en' => 'Has the weather been humid/rainy lately, and which leaves are affected first?',
                        'sn' => 'Kunze kwanga kuchinaya here, uye mashizha api akatanga kubatwa?',
                        'nd' => 'Izulu belisina yini, njalo ngamahlamvu aphi aqale ukuhlaselwa?',
                    ],
                    'options' => [
                        ['id' => 'humid_lower_leaves', 'title' => 'Wet + lower leaves', 'confidence_delta' => 0.12],
                        ['id' => 'dry_upper_leaves', 'title' => 'Dry + young leaves', 'confidence_delta' => 0.10],
                        ['id' => 'whole_plant_wilting', 'title' => 'Whole plant wilting', 'boosts_candidate' => 'bacterial_wilt', 'confidence_delta' => 0.15],
                    ],
                ],
            ],

            DiagnosisCategory::NUTRIENT_DEFICIENCY, DiagnosisCategory::NUTRIENT_TOXICITY => [
                [
                    'key' => 'leaf_age_and_yellowing_pattern',
                    'discriminates' => ['nitrogen_deficiency', 'potassium_deficiency', 'sulphur_deficiency', 'phosphorus_deficiency'],
                    'text' => [
                        'en' => 'Which leaves are affected first and where is the discolouration?',
                        'sn' => 'Mashizha api akatanga kushanduka ruvara (ezasi makuru kana epamusoro matsva)?',
                        'nd' => 'Ngamahlamvu aphi aqale ukutshintsha umbala (angaphansi kumbe amasha)?',
                    ],
                    'options' => [
                        [
                            'id' => 'old_v_midrib',
                            'title' => 'Old leaf V-midrib',
                            'boosts_candidate' => 'nitrogen_deficiency',
                            'confidence_delta' => 0.18,
                        ],
                        [
                            'id' => 'old_outer_edges',
                            'title' => 'Old leaf outer edges',
                            'boosts_candidate' => 'potassium_deficiency',
                            'confidence_delta' => 0.18,
                        ],
                        [
                            'id' => 'young_pale_or_purple',
                            'title' => 'Purple or young pale',
                            'boosts_candidate' => 'phosphorus_deficiency',
                            'confidence_delta' => 0.15,
                        ],
                    ],
                ],
                [
                    'key' => 'last_fertiliser_and_soil_moisture',
                    'discriminates' => ['nitrogen_deficiency', 'phosphorus_deficiency'],
                    'text' => [
                        'en' => 'What fertiliser was applied so far, and has the field been waterlogged or dry?',
                        'sn' => 'Makaisa fetiraiza ipi kusvika pari zvino, uye munda une mvura yakawandisa here?',
                        'nd' => 'Lifake fetilayiza bani kuze kube khathesi, njalo insimu imanzi kakhulu yini?',
                    ],
                    'options' => [
                        ['id' => 'no_topdress_yet', 'title' => 'Basal only (no AN)', 'boosts_candidate' => 'nitrogen_deficiency', 'confidence_delta' => 0.14],
                        ['id' => 'no_fertiliser_acidic', 'title' => 'No basal / sandy soil', 'confidence_delta' => 0.12],
                        ['id' => 'applied_both', 'title' => 'Applied Basal + AN', 'confidence_delta' => 0.08],
                    ],
                ],
            ],

            DiagnosisCategory::LIVESTOCK_CONDITION => [
                [
                    'key' => 'livestock_mortality_and_ticks',
                    'discriminates' => ['theileriosis_january_disease', 'newcastle_disease'],
                    'text' => [
                        'en' => 'Are any animals dying, or do they have swollen glands under the ear and cloudy eyes?',
                        'sn' => 'Pane zvipfuyo zviri kufa here kana kuzvimba pasiochedzeve nemaziso machena?',
                        'nd' => 'Zikhona izifuyo ezifayo kumbe ezivuvuke ngaphansi kwendlebe?',
                    ],
                    'options' => [
                        [
                            'id' => 'deaths_or_swollen_glands',
                            'title' => 'Yes (swollen/dying)',
                            'boosts_candidate' => 'theileriosis_january_disease',
                            'confidence_delta' => 0.20,
                            'triggers_escalation' => true,
                        ],
                        [
                            'id' => 'mild_symptoms_only',
                            'title' => 'No deaths (mild)',
                            'confidence_delta' => 0.10,
                            'triggers_escalation' => false,
                        ],
                    ],
                ],
            ],

            default => [
                [
                    'key' => 'general_spread_and_severity',
                    'discriminates' => [],
                    'text' => [
                        'en' => 'Is the problem in small patches or across the whole field?',
                        'sn' => 'Dambudziko iri riri muzvikamu zvidiki here kana mumunda wose?',
                        'nd' => 'Uhlupho lolu lukuzo zonke indawo zensimu kumbe endaweni ezilutshwana?',
                    ],
                    'options' => [
                        ['id' => 'small_patches', 'title' => 'Small patches', 'confidence_delta' => 0.12],
                        ['id' => 'whole_field', 'title' => 'Whole field', 'confidence_delta' => 0.12],
                    ],
                ],
            ],
        };
    }

    /**
     * Localize a question text map for the given LanguageCode.
     *
     * @param array<string, string> $textMap
     */
    public function localize(array $textMap, LanguageCode $language): string
    {
        return $textMap[$language->value] ?? $textMap['en'] ?? '';
    }
}
