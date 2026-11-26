<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Models\ConsentEvent;
use App\Models\Farmer;
use App\Models\PlanRequirement;
use App\Models\ServiceBooking;
use App\Models\ServiceProvider;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic M4 Service & Logistics Booking Service (zero LLM).
 * Supports spatial discovery (PostGIS ST_DWithin), plan_requirements.booking_id linking,
 * consent-gated contact reveal, and strict booking lifecycle transitions.
 */
final class ServiceBookingService
{
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'requested' => ['confirmed', 'cancelled'],
        'confirmed' => ['in_progress', 'cancelled', 'disputed'],
        'in_progress' => ['completed', 'disputed'],
        'disputed' => ['completed', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * @return Collection<int, ServiceProvider>
     */
    public function findNearbyProviders(
        string $serviceType,
        float $lat,
        float $lng,
        int $maxRadiusKm = 100
    ): Collection {
        $query = ServiceProvider::query()
            ->where('service_type', '=', $serviceType)
            ->where('is_active', '=', true)
            ->orderByDesc('is_verified')
            ->orderBy('unit_rate_minor');

        if (DB::getDriverName() === 'pgsql') {
            $maxMeters = (float) ($maxRadiusKm * 1000);

            return $query
                ->whereNotNull('location_point')
                ->whereRaw(
                    'ST_DWithin(location_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, LEAST(?, coverage_radius_km * 1000.0))',
                    [$lng, $lat, $maxMeters]
                )
                ->get();
        }

        return $query->get()->filter(function (ServiceProvider $p) use ($lat, $lng, $maxRadiusKm): bool {
            $dist = $this->haversineKm($lat, $lng, (float) $p->base_latitude, (float) $p->base_longitude);

            return $dist <= min($maxRadiusKm, (int) $p->coverage_radius_km);
        })->values();
    }

    /**
     * @param array{
     *   scheduled_date: string,
     *   quantity_scaled: int,
     *   unit?: string,
     *   plan_requirement_id?: ?string,
     *   approx_latitude?: ?float,
     *   approx_longitude?: ?float,
     *   notes?: ?string,
     *   grant_contact_consent?: bool,
     *   channel?: string
     * } $data
     */
    public function createBooking(Farmer $farmer, ServiceProvider $provider, array $data): ServiceBooking
    {
        if (!$provider->is_active) {
            throw new DomainException('Selected service provider is currently inactive.');
        }

        return DB::transaction(function () use ($farmer, $provider, $data): ServiceBooking {
            $planReq = null;
            if (!empty($data['plan_requirement_id'])) {
                $planReq = PlanRequirement::query()
                    ->with(['farmPlan'])
                    ->findOrFail((string) $data['plan_requirement_id']);

                if ($planReq->farmPlan === null || $planReq->farmPlan->farmer_id !== $farmer->id) {
                    throw new DomainException('Forbidden: plan requirement belongs to another farmer.');
                }
            }

            $qtyScaled = max(1, (int) $data['quantity_scaled']);
            $quotedMinor = (int) round(($qtyScaled / 100.0) * (int) $provider->unit_rate_minor);

            $grantConsent = (bool) ($data['grant_contact_consent'] ?? false);
            if ($grantConsent) {
                ConsentEvent::record(
                    tenantId: $provider->tenant_id,
                    farmerId: $farmer->id,
                    scope: 'service_booking_contact_reveal',
                    action: 'granted',
                    channel: (string) ($data['channel'] ?? 'web'),
                    source: 'farmer_service_booking',
                    termsVersion: (string) config('betterlink.cdpa.current_policy_version', '2026.1')
                );
            }

            $hasConsent = ConsentEvent::hasActiveConsent($provider->tenant_id, $farmer->id, 'service_booking_contact_reveal')
                || ConsentEvent::hasActiveConsent(null, $farmer->id, 'service_booking_contact_reveal');

            $booking = ServiceBooking::query()->create([
                'farmer_id' => $farmer->id,
                'service_provider_id' => $provider->id,
                'organisation_id' => $provider->tenant_id,
                'farm_plan_id' => $planReq?->farm_plan_id,
                'plan_requirement_id' => $planReq?->id,
                'service_type' => $provider->service_type,
                'status' => 'requested',
                'scheduled_date' => $data['scheduled_date'],
                'quantity_scaled' => $qtyScaled,
                'unit' => (string) ($data['unit'] ?? 'ha'),
                'quoted_amount_minor' => $quotedMinor,
                'currency' => $provider->currency,
                'province_id' => $provider->province_id,
                'district_id' => $provider->district_id,
                'approx_latitude' => $data['approx_latitude'] ?? null,
                'approx_longitude' => $data['approx_longitude'] ?? null,
                'farmer_contact_revealed_at' => $hasConsent ? now() : null,
                'notes' => $data['notes'] ?? null,
            ]);

            if ($planReq !== null) {
                $planReq->update([
                    'booking_id' => $booking->id,
                    'status' => 'booked',
                ]);
            }

            AuditLog::query()->create([
                'tenant_id' => $provider->tenant_id,
                'actor_type' => 'farmer',
                'actor_id' => $farmer->id,
                'event_type' => 'service_booking_created',
                'entity_type' => ServiceBooking::class,
                'entity_id' => $booking->id,
                'payload_json' => [
                    'service_type' => $provider->service_type,
                    'quoted_amount_minor' => $quotedMinor,
                    'plan_requirement_id' => $planReq?->id,
                ],
                'created_at' => now(),
            ]);

            return $booking->fresh();
        });
    }

    public function transitionBooking(
        ServiceBooking $booking,
        string $toStatus,
        string $actorType,
        string $actorId,
        ?string $notes = null
    ): ServiceBooking {
        $allowed = self::ALLOWED_TRANSITIONS[$booking->status] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            throw new DomainException("Illegal service booking transition from {$booking->status} to {$toStatus}.");
        }

        return DB::transaction(function () use ($booking, $toStatus, $actorType, $actorId, $notes): ServiceBooking {
            $fromStatus = $booking->status;
            $update = ['status' => $toStatus];
            if ($toStatus === 'completed') {
                $update['completed_at'] = now();
            }
            if ($notes !== null) {
                $update['notes'] = $notes;
            }

            $booking->update($update);

            AuditLog::query()->create([
                'tenant_id' => $booking->organisation_id,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'event_type' => 'service_booking_status_changed',
                'entity_type' => ServiceBooking::class,
                'entity_id' => $booking->id,
                'payload_json' => [
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'notes' => $notes,
                ],
                'created_at' => now(),
            ]);

            return $booking->fresh();
        });
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * asin(min(1.0, sqrt($a)));
    }
}
