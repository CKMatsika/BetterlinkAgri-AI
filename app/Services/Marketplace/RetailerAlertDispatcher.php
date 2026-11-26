<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Events\Marketplace\OfferStatusChangedEvent;
use App\Events\Marketplace\OutletRequestAlertedEvent;
use App\Events\Marketplace\RequestCountersUpdatedEvent;
use App\Jobs\Marketplace\SendRetailerMarketplaceAlertJob;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\RequestRecipient;

/**
 * Coordinates Reverb real-time broadcasts and WhatsApp/SMS retailer alert jobs.
 */
final class RetailerAlertDispatcher
{
    public function dispatchRecipientAlert(MarketplaceRequest $request, RequestRecipient $recipient): void
    {
        $items = $request->items()->get()->map(fn ($item) => [
            'item_name' => (string) $item->item_name,
            'quantity_scaled' => (int) $item->quantity_scaled,
            'unit' => (string) $item->unit,
        ])->values()->all();

        event(new OutletRequestAlertedEvent(
            outletId: $recipient->outlet_id,
            organisationId: $recipient->organisation_id,
            requestId: $request->id,
            recipientId: $recipient->id,
            ringNumber: (int) $recipient->ring_number,
            distanceBand: (string) $recipient->distance_band,
            items: $items,
            neededByDate: $request->needed_by_date?->toDateString(),
            expiresAt: $request->expires_at?->toIso8601String()
        ));

        SendRetailerMarketplaceAlertJob::dispatch($recipient->id, 'whatsapp');
        SendRetailerMarketplaceAlertJob::dispatch($recipient->id, 'sms')
            ->delay(now()->addSeconds(120));
    }

    /**
     * @param array{sent: int, delivered: int, opened: int, offers: int, declined: int, out_of_stock: int, current_ring: int, status: string} $counters
     */
    public function broadcastCounters(string $requestId, array $counters): void
    {
        event(new RequestCountersUpdatedEvent($requestId, $counters));
    }

    public function broadcastOfferStatus(Offer $offer): void
    {
        event(new OfferStatusChangedEvent(
            outletId: $offer->outlet_id,
            requestId: $offer->request_id,
            offerId: $offer->id,
            status: (string) $offer->status
        ));
    }
}
