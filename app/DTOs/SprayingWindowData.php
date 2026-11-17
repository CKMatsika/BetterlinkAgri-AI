<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class SprayingWindowData
{
    /**
     * @param list<string> $unsafeReasons
     */
    public function __construct(
        public string $wardName,
        public float $tempCelsius,
        public int $humidityPct,
        public float $windSpeedKmh,
        public int $rainProbabilityPct,
        public bool $isSafeToSprayNow,
        public string $recommendedWindowSummary,
        public array $unsafeReasons = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ward_name' => $this->wardName,
            'temp_celsius' => $this->tempCelsius,
            'humidity_pct' => $this->humidityPct,
            'wind_speed_kmh' => $this->windSpeedKmh,
            'rain_probability_pct' => $this->rainProbabilityPct,
            'is_safe_to_spray_now' => $this->isSafeToSprayNow,
            'recommended_window_summary' => $this->recommendedWindowSummary,
            'unsafe_reasons' => $this->unsafeReasons,
        ];
    }
}
