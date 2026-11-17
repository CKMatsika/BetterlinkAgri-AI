<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Models\Conversation;
use App\Models\Crop;
use App\Services\Diagnosis\ApplicationRateCalculator;

final class CalculateApplicationRateTool implements AiToolContract
{
    public function __construct(
        private readonly ApplicationRateCalculator $calculator
    ) {
    }

    public function name(): string
    {
        return 'calculate_application_rate';
    }

    public function description(): string
    {
        return 'Deterministically calculate fertiliser application rates (per plant, per Pfumvudza station, and per hectare + 50kg bag count) with full working.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'crop_code' => ['type' => 'string', 'description' => 'Crop code (e.g. maize)'],
                'nutrient_addressed' => ['type' => 'string', 'description' => 'Nutrient symbol (N, P, K, Ca_Lime)'],
                'field_size_ha' => ['type' => 'number', 'description' => 'Field size in hectares'],
                'pfumvudza_plots_count' => ['type' => 'integer', 'description' => 'Number of Pfumvudza plots (624m2 each)'],
            ],
            'required' => ['crop_code', 'nutrient_addressed'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $crop = Crop::query()->where('code', '=', (string) $input['crop_code'])->first();
        if ($crop === null) {
            return ['error' => 'Crop not found'];
        }

        $result = $this->calculator->calculateFertiliserRate(
            tenant: $conversation->tenant,
            crop: $crop,
            nutrientAddressed: (string) $input['nutrient_addressed'],
            fieldSizeHa: isset($input['field_size_ha']) ? (float) $input['field_size_ha'] : 1.0,
            pfumvudzaPlotsCount: isset($input['pfumvudza_plots_count']) ? (int) $input['pfumvudza_plots_count'] : null
        );

        return $result !== null ? $result->toArray() : ['error' => 'No fertiliser rate row found'];
    }
}
