<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\DTOs\ImageQualityReportData;
use App\Enums\LanguageCode;
use App\Models\Attachment;
use App\Models\Farmer;
use App\Models\FarmerLocation;
use App\Models\LocationConsent;
use App\Models\Message;
use GdImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class MediaIntakeService
{
    /**
     * Process raw image bytes: optionally extract GPS if consented, strip EXIF, resize/compress,
     * compute brightness and Laplacian sharpness scores, store to disk, and persist Attachment.
     */
    public function processAndStoreImage(
        string $rawBytes,
        Message $message,
        Farmer $farmer,
        string $originalMimeType = 'image/jpeg'
    ): ImageQualityReportData {
        $extractedLat = null;
        $extractedLng = null;

        // 1. Check if farmer has granted explicit GPS capture consent for this tenant
        $locationConsent = LocationConsent::query()
            ->where('tenant_id', '=', $message->tenant_id)
            ->where('farmer_id', '=', $farmer->id)
            ->first();

        if ($locationConsent !== null && $locationConsent->canCaptureGps()) {
            [$extractedLat, $extractedLng] = $this->extractExifGpsCoordinates($rawBytes);

            if ($extractedLat !== null && $extractedLng !== null) {
                $this->persistExtractedFarmerLocation($farmer, $message->tenant_id, $extractedLat, $extractedLng);
            }
        }

        // 2. Decode image into GD resource (re-encoding via GD strips all EXIF metadata)
        $sourceImage = @imagecreatefromstring($rawBytes);
        if (!$sourceImage instanceof GdImage) {
            throw new RuntimeException('Unable to decode uploaded image bytes.');
        }

        $origWidth = imagesx($sourceImage);
        $origHeight = imagesy($sourceImage);

        // 3. Resize if longest side exceeds target max dimension (default 1280px)
        $maxDimension = (int) config('betterlink.media.target_max_width', 1280);
        $workingImage = $this->resizeIfNeeded($sourceImage, $origWidth, $origHeight, $maxDimension);
        $finalWidth = imagesx($workingImage);
        $finalHeight = imagesy($workingImage);

        // 4. Compute deterministic image quality metrics (luminance & Laplacian variance)
        [$brightnessScore, $blurScore] = $this->computeBrightnessAndLaplacianVariance($workingImage);

        // 5. Evaluate quality gate thresholds
        $minResolution = (int) config('betterlink.media.min_resolution_px', 400);
        $minBrightness = (float) config('betterlink.media.min_brightness_score', 35.0);
        $maxBrightness = (float) config('betterlink.media.max_brightness_score', 235.0);
        $minBlurScore = (float) config('betterlink.media.min_laplacian_variance', 60.0);

        $qualityPassed = true;
        $failureReason = null;

        if (min($origWidth, $origHeight) < $minResolution) {
            $qualityPassed = false;
            $failureReason = 'resolution_too_low';
        } elseif ($brightnessScore < $minBrightness) {
            $qualityPassed = false;
            $failureReason = 'too_dark';
        } elseif ($brightnessScore > $maxBrightness) {
            $qualityPassed = false;
            $failureReason = 'overexposed';
        } elseif ($blurScore < $minBlurScore) {
            $qualityPassed = false;
            $failureReason = 'too_blurry';
        }

        // 6. Encode as compressed JPEG (EXIF-free)
        $jpegQuality = (int) config('betterlink.media.jpeg_quality', 82);
        ob_start();
        imagejpeg($workingImage, null, $jpegQuality);
        $compressedBytes = (string) ob_get_clean();

        if ($workingImage !== $sourceImage) {
            imagedestroy($workingImage);
        }
        imagedestroy($sourceImage);

        $sha256Hash = hash('sha256', $compressedBytes);
        $fileSizeBytes = strlen($compressedBytes);
        $disk = (string) config('betterlink.media.disk', 'local');
        $relativePath = sprintf(
            'tenants/%s/diagnoses/%s/%s.jpg',
            $message->tenant_id,
            now()->format('Y/m'),
            $sha256Hash
        );

        Storage::disk($disk)->put($relativePath, $compressedBytes);

        Attachment::query()->create([
            'tenant_id' => $message->tenant_id,
            'message_id' => $message->id,
            'disk' => $disk,
            'file_path' => $relativePath,
            'mime_type' => 'image/jpeg',
            'file_size_bytes' => $fileSizeBytes,
            'sha256_hash' => $sha256Hash,
            'width' => $finalWidth,
            'height' => $finalHeight,
            'blur_score' => round($blurScore, 2),
            'brightness_score' => round($brightnessScore, 2),
            'quality_passed' => $qualityPassed,
            'quality_failure_reason' => $failureReason,
            'exif_stripped' => true,
        ]);

        return new ImageQualityReportData(
            passed: $qualityPassed,
            width: $finalWidth,
            height: $finalHeight,
            brightnessScore: round($brightnessScore, 2),
            blurScore: round($blurScore, 2),
            sha256Hash: $sha256Hash,
            storedFilePath: $relativePath,
            fileSizeBytes: $fileSizeBytes,
            exifStripped: true,
            extractedLatitude: $extractedLat,
            extractedLongitude: $extractedLng,
            failureReason: $failureReason,
        );
    }

    /**
     * Build localised framing guidance when a photo is blurry, dark, or too far away.
     */
    public function buildBetterPhotoGuidance(LanguageCode $language, ?string $failureReason = null): string
    {
        $reasonHintEn = match ($failureReason) {
            'too_dark' => 'The photo is too dark to see the symptoms clearly.',
            'overexposed' => 'The photo is too bright / washed out.',
            'resolution_too_low' => 'The photo resolution is too small or taken from too far away.',
            default => 'The photo is a bit blurry or unclear.',
        };

        $reasonHintSn = match ($failureReason) {
            'too_dark' => 'Mufananidzo wakasviba zvakanyanya.',
            'overexposed' => 'Mufananidzo une chiedza chakawandisa.',
            'resolution_too_low' => 'Mufananidzo murefu kana mudiki zvakanyanya.',
            default => 'Mufananidzo hauoneki zvakanaka (blurry).',
        };

        $reasonHintNd = match ($failureReason) {
            'too_dark' => 'Umfanekiso umnyama kakhulu.',
            'overexposed' => 'Umfanekiso ulokukhanya okwedlulisileyo.',
            'resolution_too_low' => 'Umfanekiso uthethwe khatshana kakhulu.',
            default => 'Umfanekiso awubonakali kuhle (blurry).',
        };

        return match ($language) {
            LanguageCode::SHONA => "{$reasonHintSn} Ndapota tumirai mumwe mufananidzo wakajeka masikati:\n"
                . "1. Pedyo neshizha (pamusoro nepaside repasi reshizha)\n"
                . "2. Chimbwanana/chipuka chiri pamuti kana mumwoyo wembesa\n"
                . "3. Muti wose uye chikamu chemunda chakakanganisika.",
            LanguageCode::NDEBELE => "{$reasonHintNd} Sicela lithumele omunye umfanekiso ocacileyo emini:\n"
                . "1. Eduzane lehlamvu (phezulu langaphansi kwehlamvu)\n"
                . "2. Isibungu kumbe inambuzane esisilimo\n"
                . "3. Isilimo sonke kanyeindawo yensimu elohlupho.",
            LanguageCode::ENGLISH => "{$reasonHintEn} Please send a clearer photo taken in natural daylight showing:\n"
                . "1. Close-up of the affected leaf (both upper and underside)\n"
                . "2. Close-up of any insect/caterpillar on the plant funnel or stem\n"
                . "3. One shot of the whole plant and the affected area of the field.",
        };
    }

    private function resizeIfNeeded(GdImage $source, int $width, int $height, int $maxDim): GdImage
    {
        $longest = max($width, $height);
        if ($longest <= $maxDim) {
            return $source;
        }

        $scale = $maxDim / (float) $longest;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if (!$resized instanceof GdImage) {
            return $source;
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    /**
     * Sample a 160x160 grid to compute mean luminance (0..255) and 3x3 Laplacian variance (edge sharpness).
     *
     * @return array{0: float, 1: float}
     */
    private function computeBrightnessAndLaplacianVariance(GdImage $image): array
    {
        $sampleSize = 160;
        $thumb = imagecreatetruecolor($sampleSize, $sampleSize);
        if (!$thumb instanceof GdImage) {
            return [120.0, 100.0];
        }

        imagecopyresampled(
            $thumb,
            $image,
            0,
            0,
            0,
            0,
            $sampleSize,
            $sampleSize,
            imagesx($image),
            imagesy($image)
        );

        $gray = [];
        $lumSum = 0.0;
        $totalPixels = $sampleSize * $sampleSize;

        for ($y = 0; $y < $sampleSize; $y++) {
            $row = [];
            for ($x = 0; $x < $sampleSize; $x++) {
                $rgb = imagecolorat($thumb, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
                $row[$x] = $lum;
                $lumSum += $lum;
            }
            $gray[$y] = $row;
        }

        imagedestroy($thumb);

        $meanBrightness = $lumSum / $totalPixels;

        // 3x3 discrete Laplacian operator: [0, 1, 0; 1, -4, 1; 0, 1, 0]
        $lapValues = [];
        $lapSum = 0.0;

        for ($y = 1; $y < $sampleSize - 1; $y++) {
            for ($x = 1; $x < $sampleSize - 1; $x++) {
                $lap = $gray[$y - 1][$x]
                    + $gray[$y + 1][$x]
                    + $gray[$y][$x - 1]
                    + $gray[$y][$x + 1]
                    - (4.0 * $gray[$y][$x]);
                $lapValues[] = $lap;
                $lapSum += $lap;
            }
        }

        $count = count($lapValues);
        if ($count === 0) {
            return [$meanBrightness, 100.0];
        }

        $lapMean = $lapSum / $count;
        $varianceSum = 0.0;
        foreach ($lapValues as $val) {
            $diff = $val - $lapMean;
            $varianceSum += $diff * $diff;
        }

        $laplacianVariance = $varianceSum / $count;

        return [$meanBrightness, $laplacianVariance];
    }

    /**
     * Extract decimal latitude and longitude from JPEG EXIF if present.
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function extractExifGpsCoordinates(string $rawBytes): array
    {
        if (!function_exists('exif_read_data')) {
            return [null, null];
        }

        $stream = @fopen('php://memory', 'r+');
        if ($stream === false) {
            return [null, null];
        }

        fwrite($stream, $rawBytes);
        rewind($stream);

        $exif = @exif_read_data($stream, 'GPS', true);
        fclose($stream);

        if (!is_array($exif) || !isset($exif['GPS'])) {
            return [null, null];
        }

        $gps = $exif['GPS'];
        if (empty($gps['GPSLatitude']) || empty($gps['GPSLongitude'])) {
            return [null, null];
        }

        $lat = $this->convertExifGpsToDecimal(
            (array) $gps['GPSLatitude'],
            (string) ($gps['GPSLatitudeRef'] ?? 'S')
        );
        $lng = $this->convertExifGpsToDecimal(
            (array) $gps['GPSLongitude'],
            (string) ($gps['GPSLongitudeRef'] ?? 'E')
        );

        return [$lat, $lng];
    }

    /**
     * @param array<int, string> $coordParts
     */
    private function convertExifGpsToDecimal(array $coordParts, string $hemisphere): ?float
    {
        if (count($coordParts) < 3) {
            return null;
        }

        $degrees = $this->evalFraction((string) $coordParts[0]);
        $minutes = $this->evalFraction((string) $coordParts[1]);
        $seconds = $this->evalFraction((string) $coordParts[2]);

        $decimal = $degrees + ($minutes / 60.0) + ($seconds / 3600.0);
        $hemisphere = strtoupper(trim($hemisphere));

        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal *= -1.0;
        }

        return round($decimal, 7);
    }

    private function evalFraction(string $part): float
    {
        if (!str_contains($part, '/')) {
            return (float) $part;
        }

        [$num, $den] = explode('/', $part, 2);
        $denominator = (float) $den;

        return $denominator != 0.0 ? ((float) $num / $denominator) : 0.0;
    }

    private function persistExtractedFarmerLocation(
        Farmer $farmer,
        string $tenantId,
        float $lat,
        float $lng
    ): void {
        $location = FarmerLocation::query()->create([
            'farmer_id' => $farmer->id,
            'tenant_id' => $tenantId,
            'ward_id' => $farmer->ward_id,
            'latitude_encrypted' => (string) $lat,
            'longitude_encrypted' => (string) $lng,
            'latitude' => $lat,
            'longitude' => $lng,
            'source' => 'whatsapp_pin',
            'is_approximate' => false,
            'captured_at' => now(),
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'UPDATE farmer_locations SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                [$lng, $lat, $location->id]
            );
        }
    }
}
