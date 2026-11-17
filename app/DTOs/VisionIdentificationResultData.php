<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\DiagnosisCategory;

final readonly class VisionIdentificationResultData
{
    /**
     * @param list<CandidateDiagnosisData> $candidates
     * @param list<string> $missingInformation
     */
    public function __construct(
        public DiagnosisCategory $category,
        public ?string $crop,
        public ?string $growthStage,
        public array $candidates,
        public string $imageQuality, // good|poor|text_only
        public array $missingInformation = [],
        public bool $isHighStakesOrNotifiable = false,
    ) {
    }

    public function topCandidate(): ?CandidateDiagnosisData
    {
        return $this->candidates[0] ?? null;
    }

    public function topConfidence(): float
    {
        return $this->topCandidate()?->confidence ?? 0.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'category' => $this->category->value,
            'crop' => $this->crop,
            'growth_stage' => $this->growthStage,
            'candidates' => array_map(fn (CandidateDiagnosisData $c) => $c->toArray(), $this->candidates),
            'image_quality' => $this->imageQuality,
            'missing_information' => $this->missingInformation,
            'is_high_stakes_or_notifiable' => $this->isHighStakesOrNotifiable,
        ];
    }
}
