<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ImageQualityReportData
{
    public function __construct(
        public bool $passed,
        public int $width,
        public int $height,
        public float $brightnessScore,
        public float $blurScore,
        public string $sha256Hash,
        public string $storedFilePath,
        public int $fileSizeBytes,
        public bool $exifStripped,
        public ?float $extractedLatitude = null,
        public ?float $extractedLongitude = null,
        public ?string $failureReason = null,
    ) {
    }
}
