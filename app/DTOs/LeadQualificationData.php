<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\CurrencyCode;
use App\Enums\LeadTemperature;

final readonly class LeadQualificationData
{
    /**
     * @param list<string> $scoreReasons
     * @param list<array<string, mixed>> $requestedProducts
     */
    public function __construct(
        public LeadTemperature $temperature,
        public int $intentScore,
        public array $scoreReasons,
        public string $purchaseWindow, // immediate_24_48h | within_7_days | within_14_days | next_season
        public float $estimatedValue,
        public CurrencyCode $currency,
        public bool $needsCreditOrContract,
        public array $requestedProducts = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'temperature' => $this->temperature->value,
            'intent_score' => $this->intentScore,
            'score_reasons' => $this->scoreReasons,
            'purchase_window' => $this->purchaseWindow,
            'estimated_value' => $this->estimatedValue,
            'currency' => $this->currency->value,
            'needs_credit_or_contract' => $this->needsCreditOrContract,
            'requested_products' => $this->requestedProducts,
        ];
    }
}
