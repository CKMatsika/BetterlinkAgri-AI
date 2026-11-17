<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class StructuredAdvisoryData
{
    /**
     * @param array<string, mixed> $steps
     * @param list<MatchedProductRecommendationData> $products
     * @param list<ApplicationRateCalculationData> $rates
     * @param list<RichMessageCardData> $richCards
     */
    public function __construct(
        public string $advisoryType, // pest|disease|nutrient|weed|abiotic|livestock|non_chemical_escalation
        public string $summaryLine,
        public string $whatItIsSection,
        public string $whatToDoNowSection,
        public string $productOptionsSection,
        public string $howToApplySection,
        public string $safetySection,
        public string $nextStepSection,
        public string $renderedWhatsAppText,
        public array $steps,
        public array $products = [],
        public array $rates = [],
        public ?SprayingWindowData $weatherWindow = null,
        public array $richCards = [],
        public bool $requiresEscalation = false,
        public ?string $escalationReason = null,
    ) {
    }
}
