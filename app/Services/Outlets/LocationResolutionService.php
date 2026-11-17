<?php

declare(strict_types=1);

namespace App\Services\Outlets;

use App\Models\Farmer;
use App\Models\FarmerLocation;
use App\Models\LocationConsent;
use App\Models\Ward;
use App\Models\WardCentroid;

/**
 * Location Resolution Priority & Privacy Guardrail Service.
 *
 * Priority:
 * 1. WhatsApp pin / live location / app/web GPS (with explicit LocationConsent)
 * 2. Saved farm location
 * 3. Ward / growth-point centroid fallback (marked is_approximate = true)
 *
 * Guardrail:
 * NEVER share exact farmer coordinates with outlets unless share_exact_with_outlet_consent is true;
 * share only ward name and distance band.
 */
final class LocationResolutionService
{
    /**
     * Resolve farmer coordinates by priority.
     *
     * @return array{latitude: float, longitude: float, source: string, is_approximate: bool, ward: ?Ward}|null
     */
    public function resolveFarmerCoordinates(string $tenantId, Farmer $farmer): ?array
    {
        $locationConsent = LocationConsent::query()
            ->where('tenant_id', '=', $tenantId)
            ->where('farmer_id', '=', $farmer->id)
            ->first();

        // Priority 1: Recent GPS pin / live location (if GPS capture consented)
        if ($locationConsent !== null && $locationConsent->canCaptureGps()) {
            $pinLocation = FarmerLocation::query()
                ->where('farmer_id', '=', $farmer->id)
                ->whereIn('source', ['whatsapp_pin', 'live_location', 'app_gps'])
                ->latest('captured_at')
                ->first();

            if ($pinLocation !== null) {
                return [
                    'latitude' => (float) $pinLocation->latitude,
                    'longitude' => (float) $pinLocation->longitude,
                    'source' => $pinLocation->source,
                    'is_approximate' => false,
                    'ward' => $pinLocation->ward ?? $farmer->ward,
                ];
            }
        }

        // Priority 2: Saved farm location
        $savedLocation = FarmerLocation::query()
            ->where('farmer_id', '=', $farmer->id)
            ->where('source', '=', 'saved_farm')
            ->latest('captured_at')
            ->first();

        if ($savedLocation !== null) {
            return [
                'latitude' => (float) $savedLocation->latitude,
                'longitude' => (float) $savedLocation->longitude,
                'source' => 'saved_farm',
                'is_approximate' => false,
                'ward' => $savedLocation->ward ?? $farmer->ward,
            ];
        }

        // Priority 3: Ward centroid fallback (marked approximate)
        $ward = $farmer->ward ?? $farmer->farms()->first()?->ward;
        if ($ward !== null) {
            $centroid = WardCentroid::query()->where('ward_id', '=', $ward->id)->first();
            if ($centroid !== null) {
                return [
                    'latitude' => (float) $centroid->latitude,
                    'longitude' => (float) $centroid->longitude,
                    'source' => 'ward_centroid',
                    'is_approximate' => true,
                    'ward' => $ward,
                ];
            }
        }

        return null;
    }

    /**
     * Format distance band label (e.g., "< 5 km", "5–15 km", "15–30 km", "30+ km").
     */
    public function formatDistanceBand(float $distanceKm): string
    {
        if ($distanceKm < 5.0) {
            return '< 5 km';
        }
        if ($distanceKm <= 15.0) {
            return '5–15 km';
        }
        if ($distanceKm <= 30.0) {
            return '15–30 km';
        }
        if ($distanceKm <= 60.0) {
            return '30–60 km';
        }

        return '60+ km';
    }

    /**
     * Build the location disclosure string that an outlet is permitted to see.
     * Enforces: Never share exact coordinates with outlets until the farmer explicitly consents.
     */
    public function buildOutletSafeLocationDisclosure(
        string $tenantId,
        Farmer $farmer,
        ?Ward $ward,
        float $distanceKm,
        float $exactLat,
        float $exactLng
    ): string {
        $wardLabel = $ward?->name ?? 'Farmer Ward';
        $band = $this->formatDistanceBand($distanceKm);

        $locationConsent = LocationConsent::query()
            ->where('tenant_id', '=', $tenantId)
            ->where('farmer_id', '=', $farmer->id)
            ->first();

        if ($locationConsent !== null && $locationConsent->canShareExactCoordinatesWithOutlet()) {
            return sprintf('%s (%s away — GPS: %.5f, %.5f)', $wardLabel, $band, $exactLat, $exactLng);
        }

        // Strict privacy guardrail: ONLY ward and distance band
        return sprintf('%s (~%s band — exact GPS hidden until farmer contact consent)', $wardLabel, $band);
    }
}
