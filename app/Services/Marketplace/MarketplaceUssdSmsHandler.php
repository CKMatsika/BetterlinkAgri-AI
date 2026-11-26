<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\CurrencyCode;
use App\Enums\MarketplaceRequestStatus;
use App\Models\Farmer;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\RequestItem;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic plain-text USSD / SMS Marketplace command handler for feature phones.
 * Commands:
 *  - BUY <qty> <unit> <item>        (e.g. "BUY 4 bags Compound D 50kg")
 *  - OFFERS <short_ref>             (e.g. "OFFERS A1B2C3D4")
 *  - ACCEPT <short_ref> <num> [YES] (e.g. "ACCEPT A1B2C3D4 1 YES")
 */
final class MarketplaceUssdSmsHandler
{
    public function __construct(
        private readonly MarketplaceBroadcastService $broadcastService,
        private readonly MarketplaceOfferAndOrderService $offerAndOrderService
    ) {
    }

    public function canHandle(string $text): bool
    {
        return preg_match('/^(BUY|OFFERS|ACCEPT)\b/i', trim($text)) === 1;
    }

    /**
     * @return array{handled: bool, reply_text: string, request_id?: string, order_id?: string}
     */
    public function handle(Tenant $tenant, Farmer $farmer, string $rawText, string $channel = 'ussd'): array
    {
        $text = trim($rawText);

        if (preg_match('/^BUY\s+(\d+(?:\.\d+)?)\s+(\w+)\s+(.+)$/i', $text, $m) === 1) {
            return $this->handleBuy($tenant, $farmer, (float) $m[1], strtolower($m[2]), trim($m[3]), $channel);
        }

        if (preg_match('/^OFFERS\s+([A-Za-z0-9\-]+)$/i', $text, $m) === 1) {
            return $this->handleOffers($farmer, strtoupper($m[1]));
        }

        if (preg_match('/^ACCEPT\s+([A-Za-z0-9\-]+)\s+(\d+)(?:\s+(YES))?$/i', $text, $m) === 1) {
            $consent = isset($m[3]) && strtoupper($m[3]) === 'YES';

            return $this->handleAccept($farmer, strtoupper($m[1]), (int) $m[2], $consent, $channel);
        }

        return [
            'handled' => true,
            'reply_text' => 'Marketplace commands: BUY <qty> <unit> <item> | OFFERS <ref> | ACCEPT <ref> <num> YES',
        ];
    }

    /**
     * @return array{handled: bool, reply_text: string, request_id: string}
     */
    private function handleBuy(Tenant $tenant, Farmer $farmer, float $qty, string $unit, string $itemName, string $channel): array
    {
        $lat = -17.8292;
        $lng = 31.0522;
        $ward = $farmer->ward;

        $marketRequest = DB::transaction(function () use ($tenant, $farmer, $qty, $unit, $itemName, $channel, $lat, $lng, $ward): MarketplaceRequest {
            $req = MarketplaceRequest::withoutGlobalScopes()->create([
                'farmer_id' => $farmer->id,
                'origin_organisation_id' => $tenant->id,
                'request_type' => 'stock',
                'status' => MarketplaceRequestStatus::DRAFT,
                'source' => $channel === 'sms' ? 'ussd' : 'ussd',
                'province_id' => $ward?->district?->province_id,
                'district_id' => $ward?->district_id,
                'ward_id' => $ward?->id,
                'approx_latitude' => $lat,
                'approx_longitude' => $lng,
                'farmer_phone_encrypted' => $farmer->phone_encrypted,
                'delivery_preference' => 'either',
                'currency' => CurrencyCode::USD,
            ]);

            if (DB::getDriverName() === 'pgsql') {
                DB::update(
                    'UPDATE requests SET approx_location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [$lng, $lat, $req->id]
                );
            }

            RequestItem::query()->create([
                'request_id' => $req->id,
                'item_name' => $itemName,
                'quantity_scaled' => (int) round($qty * 100),
                'unit' => $unit,
            ]);

            return $req;
        });

        $recipients = $this->broadcastService->broadcastRing($marketRequest->fresh(), 1);
        $shortRef = strtoupper(substr($marketRequest->id, 0, 8));

        return [
            'handled' => true,
            'request_id' => $marketRequest->id,
            'reply_text' => sprintf(
                'Request %s posted for %s %s %s. Sent to %d nearby shops. Reply OFFERS %s to check offers.',
                $shortRef,
                rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
                $unit,
                $itemName,
                count($recipients),
                $shortRef
            ),
        ];
    }

    /**
     * @return array{handled: bool, reply_text: string}
     */
    private function handleOffers(Farmer $farmer, string $shortRef): array
    {
        $req = $this->findFarmerRequestByShortRef($farmer, $shortRef);
        if ($req === null) {
            return [
                'handled' => true,
                'reply_text' => "Request {$shortRef} not found for your phone number.",
            ];
        }

        $counters = $this->broadcastService->liveCounters($req);
        $offers = Offer::withoutGlobalScopes()
            ->with(['outlet' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('request_id', '=', $req->id)
            ->where('status', '=', 'pending')
            ->orderByDesc('rank_score_scaled')
            ->limit(3)
            ->get();

        if ($offers->isEmpty()) {
            return [
                'handled' => true,
                'reply_text' => sprintf(
                    'Ref %s: Sent %d, Opened %d, Offers 0 (Ring %d). Check again shortly.',
                    $shortRef,
                    $counters['sent'],
                    $counters['opened'],
                    $counters['current_ring']
                ),
            ];
        }

        $lines = [sprintf('Ref %s (%d offers):', $shortRef, $offers->count())];
        foreach ($offers as $idx => $offer) {
            $cur = $offer->currency instanceof \BackedEnum ? $offer->currency->value : (string) $offer->currency;
            $lines[] = sprintf(
                '%d) %s %s %s (%s)',
                $idx + 1,
                $cur,
                number_format($offer->total_price_minor / 100, 2),
                $offer->fulfilment_type,
                $offer->outlet?->name ?? 'Shop'
            );
        }
        $lines[] = "Reply ACCEPT {$shortRef} 1 YES to accept & share phone.";

        return [
            'handled' => true,
            'reply_text' => implode(' ', $lines),
        ];
    }

    /**
     * @return array{handled: bool, reply_text: string, order_id?: string}
     */
    private function handleAccept(Farmer $farmer, string $shortRef, int $rankIndex, bool $consent, string $channel): array
    {
        $req = $this->findFarmerRequestByShortRef($farmer, $shortRef);
        if ($req === null) {
            return [
                'handled' => true,
                'reply_text' => "Request {$shortRef} not found for your phone number.",
            ];
        }

        $offers = Offer::withoutGlobalScopes()
            ->where('request_id', '=', $req->id)
            ->where('status', '=', 'pending')
            ->orderByDesc('rank_score_scaled')
            ->get()
            ->values();

        $chosen = $offers->get(max(0, $rankIndex - 1));
        if ($chosen === null) {
            return [
                'handled' => true,
                'reply_text' => "Offer #{$rankIndex} not available for {$shortRef}.",
            ];
        }

        $order = $this->offerAndOrderService->acceptOffer($farmer, $chosen, $consent, $channel);
        $orderRef = strtoupper(substr($order->id, 0, 8));

        return [
            'handled' => true,
            'order_id' => $order->id,
            'reply_text' => sprintf(
                'Order %s confirmed for Ref %s. Contact shared: %s.',
                $orderRef,
                $shortRef,
                $consent ? 'YES' : 'NO'
            ),
        ];
    }

    private function findFarmerRequestByShortRef(Farmer $farmer, string $shortRef): ?MarketplaceRequest
    {
        $prefix = strtolower($shortRef);

        return MarketplaceRequest::withoutGlobalScopes()
            ->where('farmer_id', '=', $farmer->id)
            ->whereRaw('LOWER(CAST(id AS TEXT)) LIKE ?', [$prefix . '%'])
            ->latest()
            ->first();
    }
}
