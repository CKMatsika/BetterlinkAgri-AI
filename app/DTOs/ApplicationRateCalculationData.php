<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Deterministic Application Rate output showing rates in THREE ways:
 * 1. Per Plant (grams or ml + local cup/bottle cap measure)
 * 2. Per Pfumvudza Planting Station (39m x 16m = 1,456 stations)
 * 3. Per Hectare (kg/ha or L/ha + 50 kg bag count or pack count)
 * Plus step-by-step mathematical working.
 */
final readonly class ApplicationRateCalculationData
{
    /**
     * @param list<string> $workingSteps
     */
    public function __construct(
        public string $calculationType, // fertiliser | spray_chemical
        public string $productOrFertiliserName,
        public float $fieldSizeHa,
        public int $plantPopulationPerHa,
        // Fertiliser 3-way rates
        public ?float $rateGramsPerPlant = null,
        public ?string $localMeasurePerPlant = null,
        public ?float $rateGramsPerPfumvudzaStation = null,
        public ?float $totalKgPerPfumvudzaPlot = null,
        public ?float $rateKgPerHa = null,
        public ?float $totalKgForField = null,
        public ?float $bags50KgPerHa = null,
        public ?float $bags50KgForField = null,
        public ?string $placementMethod = null,
        public ?string $precautions = null,
        // Chemical Spray rates (per 16L knapsack & per ha)
        public ?float $dosePer16LKnapsackValue = null,
        public ?string $dosePer16LKnapsackUnit = null,
        public ?float $dosePerHaValue = null,
        public ?string $dosePerHaUnit = null,
        public ?float $waterVolumeLitresPerHa = null,
        public ?int $knapsacksPerHa = null,
        public ?int $knapsacksForField = null,
        public ?float $totalProductForFieldValue = null,
        public array $workingSteps = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'calculation_type' => $this->calculationType,
            'product_or_fertiliser_name' => $this->productOrFertiliserName,
            'field_size_ha' => $this->fieldSizeHa,
            'plant_population_per_ha' => $this->plantPopulationPerHa,
            'per_plant' => [
                'grams' => $this->rateGramsPerPlant,
                'local_measure' => $this->localMeasurePerPlant,
            ],
            'per_pfumvudza_station' => [
                'grams_per_station' => $this->rateGramsPerPfumvudzaStation,
                'stations_per_standard_plot' => 1456,
                'total_kg_per_plot' => $this->totalKgPerPfumvudzaPlot,
            ],
            'per_hectare_and_field' => [
                'rate_kg_per_ha' => $this->rateKgPerHa,
                'bags_50kg_per_ha' => $this->bags50KgPerHa,
                'total_kg_for_field' => $this->totalKgForField,
                'bags_50kg_for_field' => $this->bags50KgForField,
            ],
            'spray_knapsack_and_field' => [
                'dose_per_16l_knapsack_value' => $this->dosePer16LKnapsackValue,
                'dose_per_16l_knapsack_unit' => $this->dosePer16LKnapsackUnit,
                'dose_per_ha_value' => $this->dosePerHaValue,
                'dose_per_ha_unit' => $this->dosePerHaUnit,
                'water_volume_litres_per_ha' => $this->waterVolumeLitresPerHa,
                'knapsacks_16l_per_ha' => $this->knapsacksPerHa,
                'knapsacks_16l_for_field' => $this->knapsacksForField,
                'total_product_for_field_value' => $this->totalProductForFieldValue,
            ],
            'placement_method' => $this->placementMethod,
            'precautions' => $this->precautions,
            'working_steps' => $this->workingSteps,
        ];
    }
}
