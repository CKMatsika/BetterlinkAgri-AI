<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class MatchedProductRecommendationData
{
    /**
     * @param list<array{size: string, price_usd: float, price_zig: float}> $packSizes
     * @param list<string> $ppeRequirements
     * @param array<string, string> $safetyWarnings
     */
    public function __construct(
        public string $productId,
        public string $sku,
        public string $productName,
        public ?string $brandName,
        public string $activeIngredient,
        public string $modeOfActionGroup,
        public string $registrationNumber,
        public float $agronomicFitRankScore,
        public float $dosePer16LKnapsackValue,
        public string $dosePer16LKnapsackUnit,
        public float $dosePerHaValue,
        public string $dosePerHaUnit,
        public float $waterVolumePerHaLitres,
        public string $mixingOrder,
        public string $applicationTimingNotes,
        public ?int $reapplicationIntervalDays,
        public int $phiDays,
        public int $reiHours,
        public array $packSizes,
        public array $ppeRequirements,
        public array $safetyWarnings,
        public string $citedDocumentTitle,
        public ?int $citedPageNumber,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'brand_name' => $this->brandName,
            'active_ingredient' => $this->activeIngredient,
            'mode_of_action_group' => $this->modeOfActionGroup,
            'registration_number' => $this->registrationNumber,
            'agronomic_fit_rank_score' => $this->agronomicFitRankScore,
            'dose_per_16l_knapsack_value' => $this->dosePer16LKnapsackValue,
            'dose_per_16l_knapsack_unit' => $this->dosePer16LKnapsackUnit,
            'dose_per_ha_value' => $this->dosePerHaValue,
            'dose_per_ha_unit' => $this->dosePerHaUnit,
            'water_volume_per_ha_litres' => $this->waterVolumePerHaLitres,
            'mixing_order' => $this->mixingOrder,
            'application_timing_notes' => $this->applicationTimingNotes,
            'reapplication_interval_days' => $this->reapplicationIntervalDays,
            'phi_days' => $this->phiDays,
            'rei_hours' => $this->reiHours,
            'pack_sizes' => $this->packSizes,
            'ppe_requirements' => $this->ppeRequirements,
            'safety_warnings' => $this->safetyWarnings,
            'cited_document_title' => $this->citedDocumentTitle,
            'cited_page_number' => $this->citedPageNumber,
        ];
    }
}
