<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Contracts\AiProviderContract;

/**
 * Deterministic AI Provider bound ONLY in local/testing environments when AI_DEFAULT_PROVIDER=fake.
 * Never bound in production (enforced by AppServiceProvider and ProductionDatabaseGuard).
 */
final class FakeAiProvider implements AiProviderContract
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function analyzeImageForDiagnosis(
        string $base64Image,
        string $mimeType,
        string $systemPrompt,
        string $userPrompt,
        array $context = []
    ): array {
        $lower = strtolower($userPrompt . ' ' . json_encode($context));

        if (str_contains($lower, 'yellow') || str_contains($lower, 'nitrogen') || str_contains($lower, 'yero')) {
            return [
                'category' => 'nutrient_deficiency',
                'crop' => 'maize',
                'growth_stage' => 'v4_v6_topdress',
                'candidates' => [
                    [
                        'code' => 'nitrogen_deficiency',
                        'name' => 'Nitrogen (N) Deficiency',
                        'local_names' => [
                            'sn' => 'Kushomeka kweNitrogen (mashizha ezasi anoita yero V)',
                            'nd' => 'Ukusilela kwe-Nitrogen (amahlamvu angaphansi aba luthanga)',
                        ],
                        'type' => 'nutrient_deficiency',
                        'confidence' => 0.72,
                        'visible_evidence' => [
                            'Pale V-shaped yellowing starting from the tip of older lower leaves moving along the midrib',
                        ],
                        'look_alikes' => [
                            'Potassium (K) Deficiency (yellowing on outer leaf margins)',
                            'Sulphur (S) Deficiency (uniform yellowing on younger whorl leaves)',
                        ],
                    ],
                    [
                        'code' => 'potassium_deficiency',
                        'name' => 'Potassium (K) Deficiency',
                        'local_names' => [
                            'sn' => 'Kushomeka kwePotassium',
                            'nd' => 'Ukusilela kwe-Potassium',
                        ],
                        'type' => 'nutrient_deficiency',
                        'confidence' => 0.18,
                        'visible_evidence' => ['Lower leaf chlorosis'],
                        'look_alikes' => ['Nitrogen (N) Deficiency'],
                    ],
                    [
                        'code' => 'sulphur_deficiency',
                        'name' => 'Sulphur (S) Deficiency',
                        'local_names' => [
                            'sn' => 'Kushomeka kweSulphur',
                            'nd' => 'Ukusilela kwe-Sulphur',
                        ],
                        'type' => 'nutrient_deficiency',
                        'confidence' => 0.10,
                        'visible_evidence' => ['General pale foliage'],
                        'look_alikes' => ['Nitrogen (N) Deficiency'],
                    ],
                ],
                'image_quality' => 'good',
                'missing_information' => ['leaf_age_affected', 'field_size_ha', 'ward'],
            ];
        }

        if (str_contains($lower, 'january') || str_contains($lower, 'theileria') || str_contains($lower, 'cattle') || str_contains($lower, 'mombe')) {
            return [
                'category' => 'livestock_condition',
                'crop' => 'cattle',
                'growth_stage' => 'adult',
                'candidates' => [
                    [
                        'code' => 'theileriosis_january_disease',
                        'name' => 'Cattle Theileriosis (January Disease)',
                        'local_names' => [
                            'sn' => 'Chirwere cheJanuary Disease (Chigwere chehumba)',
                            'nd' => 'Umkhuhlane weJanuary Disease (Amankwahla)',
                        ],
                        'type' => 'livestock_condition',
                        'confidence' => 0.84,
                        'visible_evidence' => [
                            'Swollen parotid lymph node below the ear',
                            'Cloudy corneal opacity and lacrimation',
                        ],
                        'look_alikes' => ['Heartwater', 'Babesiosis (Redwater)'],
                    ],
                ],
                'image_quality' => 'good',
                'missing_information' => [],
            ];
        }

        return [
            'category' => 'pest',
            'crop' => 'maize',
            'growth_stage' => 'V6_vegetative',
            'candidates' => [
                [
                    'code' => 'fall_armyworm',
                    'name' => 'Fall Armyworm (Spodoptera frugiperda)',
                    'local_names' => [
                        'sn' => 'Gonye remunhava / Honye yechibage',
                        'nd' => 'Amacimbi / Impethu yomumbu',
                    ],
                    'type' => 'pest',
                    'confidence' => 0.74,
                    'visible_evidence' => [
                        'Ragged window-pane feeding damage on young whorl leaves',
                        'Moist sawdust-like frass visible deep in the maize funnel',
                    ],
                    'look_alikes' => [
                        'African Maize Stalk Borer (Busseola fusca)',
                        'African Bollworm (Helicoverpa armigera)',
                    ],
                ],
                [
                    'code' => 'maize_stalk_borer',
                    'name' => 'African Maize Stalk Borer (Busseola fusca)',
                    'local_names' => [
                        'sn' => 'Chiburi / Gonye redzinde',
                        'nd' => 'Isibungu somumbu',
                    ],
                    'type' => 'pest',
                    'confidence' => 0.19,
                    'visible_evidence' => [
                        'Shot-hole feeding pattern across unrolling funnel leaves',
                    ],
                    'look_alikes' => [
                        'Fall Armyworm (Spodoptera frugiperda)',
                    ],
                ],
                [
                    'code' => 'bollworm',
                    'name' => 'African Bollworm (Helicoverpa armigera)',
                    'local_names' => [
                        'sn' => 'Gonye redonje nemadomasi (Bollworm)',
                        'nd' => 'Impethu yotshinda lamatamatisi',
                    ],
                    'type' => 'pest',
                    'confidence' => 0.07,
                    'visible_evidence' => [
                        'Caterpillar feeding on foliage',
                    ],
                    'look_alikes' => [
                        'Fall Armyworm (Spodoptera frugiperda)',
                    ],
                ],
            ],
            'image_quality' => 'good',
            'missing_information' => ['insect_head_and_tail_markings', 'percentage_plants_affected', 'field_size_ha'],
        ];
    }

    /**
     * @param list<array{role: string, content: mixed}> $messages
     * @param list<array<string, mixed>> $tools
     * @return array{text: string, tool_calls: list<array{id: string, name: string, input: array<string, mixed>}>, model: string, prompt_tokens: int, completion_tokens: int}
     */
    public function chatWithTools(
        string $systemPrompt,
        array $messages,
        array $tools = []
    ): array {
        return [
            'text' => 'FakeAiProvider local test response.',
            'tool_calls' => [],
            'model' => 'fake-local-test',
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
        ];
    }
}
