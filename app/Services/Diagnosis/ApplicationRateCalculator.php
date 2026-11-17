<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\DTOs\ApplicationRateCalculationData;
use App\DTOs\MatchedProductRecommendationData;
use App\Models\Crop;
use App\Models\FertiliserRecommendation;
use App\Models\Tenant;

/**
 * Deterministic Application Rate Calculator (calculate_application_rate tool backing service).
 *
 * Calculates:
 * 1. Nutrient / Fertiliser application rates shown THREE ways:
 *    - Per plant (grams + local cup/bottle-cap measure)
 *    - Per Pfumvudza / Intwasa planting station (39m x 16m = 624m2, 1,456 stations)
 *    - Per hectare (kg/ha + 50 kg bag count) and scaled to the farmer's field size
 * 2. Chemical spray rates:
 *    - Dose per 16 L knapsack
 *    - Dose per hectare + total product and knapsacks needed for the farmer's field size
 */
final class ApplicationRateCalculator
{
    public function calculateFertiliserRate(
        Tenant $tenant,
        Crop $crop,
        string $nutrientAddressed,
        float $fieldSizeHa = 1.0,
        ?int $pfumvudzaPlotsCount = null,
        ?int $rowSpacingCm = null,
        ?int $inRowSpacingCm = null,
        ?string $naturalRegion = null
    ): ?ApplicationRateCalculationData {
        $query = FertiliserRecommendation::withoutGlobalScopes()
            ->where('tenant_id', '=', $tenant->id)
            ->where('crop_id', '=', $crop->id)
            ->where('nutrient_addressed', '=', $nutrientAddressed);

        if ($naturalRegion !== null) {
            $query->whereIn('natural_region', [$naturalRegion, 'ALL']);
        }

        /** @var FertiliserRecommendation|null $rec */
        $rec = $query->first();
        if ($rec === null) {
            return null;
        }

        $rowCm = $rowSpacingCm ?: $crop->default_row_spacing_cm;
        $inRowCm = $inRowSpacingCm ?: $crop->default_inrow_spacing_cm;

        $rowMeters = max(0.10, $rowCm / 100.0);
        $inRowMeters = max(0.05, $inRowCm / 100.0);
        $plantPopPerHa = (int) round(10000.0 / ($rowMeters * $inRowMeters));

        $pfumvudzaStationsPerPlot = (int) config('betterlink.agronomy.pfumvudza_standard_stations', 1456);
        $bagSizeKg = (float) config('betterlink.agronomy.standard_fertiliser_bag_kg', 50);

        $effectivePlots = $pfumvudzaPlotsCount !== null && $pfumvudzaPlotsCount > 0 ? $pfumvudzaPlotsCount : 1;
        if ($pfumvudzaPlotsCount !== null && $pfumvudzaPlotsCount > 0) {
            $fieldSizeHa = round($pfumvudzaPlotsCount * 0.0624, 4);
        }

        $gramsPerPlant = (float) $rec->rate_grams_per_plant;
        $gramsPerStation = (float) $rec->rate_grams_per_pfumvudza_station;
        $kgPerPlot = round(($gramsPerStation * $pfumvudzaStationsPerPlot) / 1000.0, 2);
        $totalKgForPlots = round($kgPerPlot * $effectivePlots, 2);

        $rateKgPerHa = (float) $rec->rate_kg_per_ha;
        $totalKgForField = round($rateKgPerHa * $fieldSizeHa, 2);
        $bagsPerHa = round($rateKgPerHa / $bagSizeKg, 2);
        $bagsForField = round($totalKgForField / $bagSizeKg, 2);

        $workingSteps = [
            sprintf(
                '1. Per Plant: %.1f g per plant (%s) at %d cm x %d cm spacing (~%s plants/ha).',
                $gramsPerPlant,
                $rec->local_measure_equivalent,
                $rowCm,
                $inRowCm,
                number_format($plantPopPerHa)
            ),
            sprintf(
                '2. Per Pfumvudza Station (39m x 16m plot = %s stations, 2 plants/station): %.1f g/station × %s stations = %.2f kg per plot%s.',
                number_format($pfumvudzaStationsPerPlot),
                $gramsPerStation,
                number_format($pfumvudzaStationsPerPlot),
                $kgPerPlot,
                $effectivePlots > 1 ? sprintf(' (%.2f kg for %d plots)', $totalKgForPlots, $effectivePlots) : ''
            ),
            sprintf(
                '3. Per Hectare & Field Size (%.2f ha): %.0f kg/ha (%.1f × 50 kg bags/ha) × %.2f ha = %.2f kg total (%.2f × 50 kg bags).',
                $fieldSizeHa,
                $rateKgPerHa,
                $bagsPerHa,
                $fieldSizeHa,
                $totalKgForField,
                $bagsForField
            ),
        ];

        return new ApplicationRateCalculationData(
            calculationType: 'fertiliser',
            productOrFertiliserName: $rec->fertiliser_type_name,
            fieldSizeHa: $fieldSizeHa,
            plantPopulationPerHa: $plantPopPerHa,
            rateGramsPerPlant: $gramsPerPlant,
            localMeasurePerPlant: $rec->local_measure_equivalent,
            rateGramsPerPfumvudzaStation: $gramsPerStation,
            totalKgPerPfumvudzaPlot: $kgPerPlot,
            rateKgPerHa: $rateKgPerHa,
            totalKgForField: $totalKgForField,
            bags50KgPerHa: $bagsPerHa,
            bags50KgForField: $bagsForField,
            placementMethod: $rec->placement_method,
            precautions: $rec->precautions_notes,
            workingSteps: $workingSteps,
        );
    }

    public function calculateSprayChemicalRate(
        MatchedProductRecommendationData $product,
        float $fieldSizeHa = 1.0
    ): ApplicationRateCalculationData {
        $knapsackLitres = (float) config('betterlink.agronomy.standard_knapsack_litres', 16);
        $waterPerHa = $product->waterVolumePerHaLitres > 0 ? $product->waterVolumePerHaLitres : 200.0;

        $knapsacksPerHa = (int) ceil($waterPerHa / $knapsackLitres);
        $knapsacksForField = max(1, (int) ceil($knapsacksPerHa * $fieldSizeHa));
        $totalProductForField = round($product->dosePerHaValue * $fieldSizeHa, 2);

        $workingSteps = [
            sprintf(
                '1. Per 16 L Knapsack: Mix %.1f %s of %s in 16 L clean water.',
                $product->dosePer16LKnapsackValue,
                $product->dosePer16LKnapsackUnit,
                $product->productName
            ),
            sprintf(
                '2. Per Hectare: %.1f %s/ha in %.0f L water/ha (~%d × 16 L knapsacks per ha).',
                $product->dosePerHaValue,
                $product->dosePerHaUnit,
                $waterPerHa,
                $knapsacksPerHa
            ),
            sprintf(
                '3. For Your Field (%.2f ha): %.1f %s total across ~%d × 16 L knapsacks.',
                $fieldSizeHa,
                $totalProductForField,
                $product->dosePerHaUnit,
                $knapsacksForField
            ),
        ];

        return new ApplicationRateCalculationData(
            calculationType: 'spray_chemical',
            productOrFertiliserName: $product->productName,
            fieldSizeHa: $fieldSizeHa,
            plantPopulationPerHa: 44444,
            dosePer16LKnapsackValue: $product->dosePer16LKnapsackValue,
            dosePer16LKnapsackUnit: $product->dosePer16LKnapsackUnit,
            dosePerHaValue: $product->dosePerHaValue,
            dosePerHaUnit: $product->dosePerHaUnit,
            waterVolumeLitresPerHa: $waterPerHa,
            knapsacksPerHa: $knapsacksPerHa,
            knapsacksForField: $knapsacksForField,
            totalProductForFieldValue: $totalProductForField,
            workingSteps: $workingSteps,
        );
    }
}
