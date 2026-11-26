<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Jobs\Marketplace\ExpandMarketplaceRingJob;
use App\Jobs\Marketplace\ExpireMarketplaceRequestJob;
use App\Models\MarketplaceNotification;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\Outlet;
use App\Models\RequestRecipient;
use App\Models\SellerVerification;
use App\Models\StockSignal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic spatial ring broadcast, opened_at tracking, and ring-expansion engine.
 * Uses real PostGIS ST_DWithin on PostgreSQL (Haversine only on SQLite local/test).
 */
final class MarketplaceBroadcastService
{
    public function __construct(
        private readonly MarketplaceRequestStateMachine $stateMachine,
        private readonly RetailerAlertDispatcher $alertDispatcher
    ) {
    }

    /**
     * Broadcast a MarketplaceRequest to newly eligible outlets in ring $ringNumber (1, 2, or 3).
     * Never re-notifies outlets already present in request_recipients.
     *
     * @return list<RequestRecipient>
     */
    public function broadcastRing(MarketplaceRequest $request, int $ringNumber = 1): array
    {
        /** @var list<int> $ringsKm */
        $ringsKm = (array) config('betterlink.marketplace.rings_km', [10, 25, 50]);
        $ringIndex = max(0, $ringNumber - 1);
        $radiusKm = (int) ($ringsKm[$ringIndex] ?? end($ringsKm) ?: 50);
        $prevRadiusKm = $ringIndex > 0 ? (int) ($ringsKm[$ringIndex - 1] ?? 0) : 0;
        $distanceBand = "{$prevRadiusKm}-{$radiusKm}km";

        $timeoutMinutes = max(1, (int) config('betterlink.marketplace.ring_timeout_minutes', 10));
        $lifetimeHours = max(1, (int) config('betterlink.marketplace.request_lifetime_hours', 24));

        $targetState = match ($ringNumber) {
            1 => MarketplaceRequestStatus::BROADCAST_RING_1,
            2 => MarketplaceRequestStatus::BROADCAST_RING_2,
            default => MarketplaceRequestStatus::BROADCAST_RING_3,
        };

        $alreadyNotifiedOutletIds = RequestRecipient::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->pluck('outlet_id')
            ->all();

        $eligibleOutlets = $this->findEligibleOutletsInRadius(
            lat: (float) ($request->approx_latitude ?? -17.8292),
            lng: (float) ($request->approx_longitude ?? 31.0522),
            radiusKm: $radiusKm,
            excludeOutletIds: $alreadyNotifiedOutletIds
        );

        $createdRecipients = [];
        $now = now();

        DB::transaction(function () use (
            $request,
            $ringNumber,
            $distanceBand,
            $timeoutMinutes,
            $lifetimeHours,
            $targetState,
            $eligibleOutlets,
            $now,
            &$createdRecipients
        ): void {
            if ($request->status !== $targetState) {
                $this->stateMachine->transitionTo($request, $targetState, "broadcast_ring_{$ringNumber}", [
                    'ring_number' => $ringNumber,
                    'newly_notified_count' => $eligibleOutlets->count(),
                ]);
            }

            $request->update([
                'current_ring' => $ringNumber,
                'ring_expires_at' => $now->copy()->addMinutes($timeoutMinutes),
                'expires_at' => $request->expires_at ?? $now->copy()->addHours($lifetimeHours),
            ]);

            foreach ($eligibleOutlets as $outlet) {
                $recipient = RequestRecipient::withoutGlobalScopes()->create([
                    'request_id' => $request->id,
                    'organisation_id' => $outlet->tenant_id,
                    'outlet_id' => $outlet->id,
                    'ring_number' => $ringNumber,
                    'distance_band' => $distanceBand,
                    'notified_at' => $now,
                ]);

                MarketplaceNotification::query()->create([
                    'recipient_type' => 'outlet',
                    'recipient_id' => $outlet->id,
                    'organisation_id' => $outlet->tenant_id,
                    'channel' => 'reverb',
                    'event_type' => 'marketplace_request_broadcast',
                    'title' => 'New nearby farmer request',
                    'body' => "New request within {$distanceBand}. Open to submit an offer.",
                    'payload_json' => [
                        'request_id' => $request->id,
                        'recipient_id' => $recipient->id,
                        'distance_band' => $distanceBand,
                        'ring_number' => $ringNumber,
                    ],
                    'status' => 'queued',
                ]);

                $createdRecipients[] = $recipient;
            }
        });

        // Schedule delayed ring expansion timer on Horizon
        ExpandMarketplaceRingJob::dispatch($request->id, $ringNumber)
            ->delay($now->copy()->addMinutes($timeoutMinutes));

        // On Ring 1, also schedule overall request expiry timer
        if ($ringNumber === 1) {
            ExpireMarketplaceRequestJob::dispatch($request->id)
                ->delay($request->fresh()->expires_at ?? $now->copy()->addHours($lifetimeHours));
        }

        // Dispatch real-time Reverb alerts + WhatsApp/SMS retailer alerts & live counters
        foreach ($createdRecipients as $recipient) {
            $this->alertDispatcher->dispatchRecipientAlert($request, $recipient);
        }
        $this->alertDispatcher->broadcastCounters($request->id, $this->liveCounters($request->fresh()));

        return $createdRecipients;
    }

    /**
     * Record that a retailer opened the request alert (idempotent) and return live counters.
     *
     * @return array{sent: int, delivered: int, opened: int, offers: int, declined: int, out_of_stock: int, current_ring: int, status: string}
     */
    public function recordOpened(RequestRecipient $recipient): array
    {
        if ($recipient->opened_at === null) {
            $recipient->update([
                'delivered_at' => $recipient->delivered_at ?? now(),
                'opened_at' => now(),
            ]);
        }

        $request = MarketplaceRequest::withoutGlobalScopes()->findOrFail($recipient->request_id);
        $counters = $this->liveCounters($request);
        $this->alertDispatcher->broadcastCounters($request->id, $counters);

        return $counters;
    }

    /**
     * Record a non-offer response ('declined' or 'out_of_stock') from a notified retailer.
     */
    public function recordRecipientResponse(RequestRecipient $recipient, string $response): RequestRecipient
    {
        $recipient->update([
            'opened_at' => $recipient->opened_at ?? now(),
            'responded_at' => now(),
            'response' => $response,
        ]);

        if ($response === 'out_of_stock') {
            $request = MarketplaceRequest::withoutGlobalScopes()->with('items')->find($recipient->request_id);
            $firstItem = $request?->items->first();

            StockSignal::withoutGlobalScopes()->create([
                'request_id' => $recipient->request_id,
                'organisation_id' => $recipient->organisation_id,
                'outlet_id' => $recipient->outlet_id,
                'product_id' => $firstItem?->product_id,
                'category' => $firstItem?->category,
                'district_id' => $request?->district_id,
                'ward_id' => $request?->ward_id,
                'signal_type' => 'out_of_stock_response',
                'quantity_requested_scaled' => $firstItem?->quantity_scaled,
                'occurred_at' => now(),
            ]);
        }

        return $recipient->fresh();
    }

    /**
     * Invoked by delayed ExpandMarketplaceRingJob when a ring timer expires.
     */
    public function handleRingTimeout(MarketplaceRequest $request, int $timedOutRing): void
    {
        $status = $request->status;
        if (!in_array($status, [
            MarketplaceRequestStatus::BROADCAST_RING_1,
            MarketplaceRequestStatus::BROADCAST_RING_2,
            MarketplaceRequestStatus::BROADCAST_RING_3,
            MarketplaceRequestStatus::OFFERS_RECEIVED,
        ], true)) {
            return;
        }

        // Ignore stale timers for earlier rings if already expanded
        if ((int) $request->current_ring > $timedOutRing) {
            return;
        }

        $minOffers = max(1, (int) config('betterlink.marketplace.min_offers_to_stop_expansion', 1));
        $pendingOffersCount = Offer::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->where('status', '=', 'pending')
            ->count();

        if ($pendingOffersCount >= $minOffers) {
            return;
        }

        /** @var list<int> $ringsKm */
        $ringsKm = (array) config('betterlink.marketplace.rings_km', [10, 25, 50]);
        $nextRing = $timedOutRing + 1;

        if ($nextRing <= count($ringsKm)) {
            $nextRadiusKm = (int) $ringsKm[$nextRing - 1];
            $this->broadcastRing($request, $nextRing);

            MarketplaceNotification::query()->create([
                'recipient_type' => 'farmer',
                'recipient_id' => $request->farmer_id,
                'organisation_id' => $request->origin_organisation_id,
                'channel' => 'whatsapp',
                'event_type' => 'marketplace_ring_expanded',
                'title' => 'Expanding search radius',
                'body' => "No offers yet, now asking shops within {$nextRadiusKm} km.",
                'payload_json' => [
                    'request_id' => $request->id,
                    'ring_number' => $nextRing,
                    'radius_km' => $nextRadiusKm,
                ],
                'status' => 'queued',
            ]);

            return;
        }

        // All rings exhausted with insufficient offers -> escalate upstream
        $this->stateMachine->transitionTo($request, MarketplaceRequestStatus::UPSTREAM_ESCALATED, 'all_rings_exhausted', [
            'final_ring' => $timedOutRing,
            'offers_count' => $pendingOffersCount,
        ]);

        $firstItem = $request->items()->first();
        $anyRecipient = RequestRecipient::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->first();

        if ($anyRecipient !== null) {
            StockSignal::withoutGlobalScopes()->create([
                'request_id' => $request->id,
                'organisation_id' => $anyRecipient->organisation_id,
                'outlet_id' => $anyRecipient->outlet_id,
                'product_id' => $firstItem?->product_id,
                'category' => $firstItem?->category,
                'district_id' => $request->district_id,
                'ward_id' => $request->ward_id,
                'signal_type' => 'ring_exhausted',
                'quantity_requested_scaled' => $firstItem?->quantity_scaled,
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * @return array{sent: int, delivered: int, opened: int, offers: int, declined: int, out_of_stock: int, current_ring: int, status: string}
     */
    public function liveCounters(MarketplaceRequest $request): array
    {
        $recipients = RequestRecipient::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->get();

        $offersCount = Offer::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->whereIn('status', ['pending', 'accepted'])
            ->count();

        return [
            'sent' => $recipients->count(),
            'delivered' => $recipients->whereNotNull('delivered_at')->count(),
            'opened' => $recipients->whereNotNull('opened_at')->count(),
            'offers' => $offersCount,
            'declined' => $recipients->where('response', 'declined')->count(),
            'out_of_stock' => $recipients->where('response', 'out_of_stock')->count(),
            'current_ring' => (int) $request->current_ring,
            'status' => $request->status instanceof \BackedEnum ? $request->status->value : (string) $request->status,
        ];
    }

    /**
     * @param list<string> $excludeOutletIds
     * @return Collection<int, Outlet>
     */
    private function findEligibleOutletsInRadius(
        float $lat,
        float $lng,
        int $radiusKm,
        array $excludeOutletIds
    ): Collection {
        $retailTypes = [OrganisationType::RETAILER->value, OrganisationType::INDEPENDENT_RETAILER->value];
        $requireVerified = (bool) config('betterlink.marketplace.require_verified_sellers', false);

        $query = Outlet::withoutGlobalScopes()
            ->select('outlets.*')
            ->join('tenants', 'tenants.id', '=', 'outlets.tenant_id')
            ->whereNull('outlets.deleted_at')
            ->whereNull('tenants.deleted_at')
            ->where('outlets.is_active', '=', true)
            ->where('tenants.is_active', '=', true)
            ->whereIn('tenants.organisation_type', $retailTypes)
            ->when($excludeOutletIds !== [], fn ($q) => $q->whereNotIn('outlets.id', $excludeOutletIds));

        if ($requireVerified) {
            $query->whereExists(function ($sub): void {
                $sub->select(DB::raw(1))
                    ->from('seller_verifications')
                    ->whereColumn('seller_verifications.organisation_id', 'outlets.tenant_id')
                    ->where('seller_verifications.status', '=', 'verified')
                    ->whereNull('seller_verifications.deleted_at');
            });
        } else {
            // Even when unverified sellers are permitted in pilot, never broadcast to suspended sellers
            $suspendedOrgIds = SellerVerification::query()
                ->where('status', '=', 'suspended')
                ->pluck('organisation_id')
                ->all();
            if ($suspendedOrgIds !== []) {
                $query->whereNotIn('outlets.tenant_id', $suspendedOrgIds);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            $radiusMeters = (float) ($radiusKm * 1000);
            return $query
                ->whereNotNull('outlets.location_point')
                ->whereRaw(
                    'ST_DWithin(outlets.location_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                    [$lng, $lat, $radiusMeters]
                )
                ->get();
        }

        // SQLite local-only fallback
        return $query->get()->filter(function (Outlet $o) use ($lat, $lng, $radiusKm): bool {
            return $this->haversineKm($lat, $lng, (float) $o->latitude, (float) $o->longitude) <= $radiusKm;
        })->values();
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
