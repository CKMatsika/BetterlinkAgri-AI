<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\CurrencyCode;
use App\Enums\MarketplaceRequestStatus;
use App\Models\ConsentEvent;
use App\Models\Farmer;
use App\Models\MarketplaceNotification;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\OrderEvent;
use App\Models\RequestRecipient;
use DomainException;
use Illuminate\Support\Facades\DB;

final class MarketplaceOfferAndOrderService
{
    public function __construct(
        private readonly MarketplaceRequestStateMachine $stateMachine,
        private readonly OfferRankingService $rankingService,
        private readonly MarketplaceBroadcastService $broadcastService,
        private readonly RetailerAlertDispatcher $alertDispatcher
    ) {
    }

    /**
     * Submit an offer from a notified retailer outlet, transition request to OFFERS_RECEIVED,
     * and deterministically re-rank all pending offers.
     *
     * @param array{
     *   unit_price_minor: int,
     *   total_price_minor: int,
     *   currency?: string,
     *   quantity_available_scaled: int,
     *   fulfilment_type?: string,
     *   delivery_fee_minor?: int,
     *   valid_hours?: int,
     *   note?: ?string,
     *   submitted_by_user_id?: ?string
     * } $data
     */
    public function submitOffer(RequestRecipient $recipient, array $data): Offer
    {
        $request = MarketplaceRequest::withoutGlobalScopes()->findOrFail($recipient->request_id);

        if (!in_array($request->status, [
            MarketplaceRequestStatus::BROADCAST_RING_1,
            MarketplaceRequestStatus::BROADCAST_RING_2,
            MarketplaceRequestStatus::BROADCAST_RING_3,
            MarketplaceRequestStatus::OFFERS_RECEIVED,
            MarketplaceRequestStatus::UPSTREAM_ESCALATED,
        ], true)) {
            throw new DomainException('Cannot submit an offer for a request in state ' . $request->status->value);
        }

        $offer = DB::transaction(function () use ($request, $recipient, $data): Offer {
            $offer = Offer::withoutGlobalScopes()->create([
                'request_id' => $request->id,
                'organisation_id' => $recipient->organisation_id,
                'outlet_id' => $recipient->outlet_id,
                'submitted_by_user_id' => $data['submitted_by_user_id'] ?? null,
                'status' => 'pending',
                'unit_price_minor' => (int) $data['unit_price_minor'],
                'total_price_minor' => (int) $data['total_price_minor'],
                'currency' => CurrencyCode::tryFrom((string) ($data['currency'] ?? 'USD')) ?? CurrencyCode::USD,
                'quantity_available_scaled' => (int) $data['quantity_available_scaled'],
                'fulfilment_type' => (string) ($data['fulfilment_type'] ?? 'pickup'),
                'delivery_fee_minor' => (int) ($data['delivery_fee_minor'] ?? 0),
                'valid_until' => now()->addHours(max(1, (int) ($data['valid_hours'] ?? 12))),
                'note' => $data['note'] ?? null,
            ]);

            $recipient->update([
                'opened_at' => $recipient->opened_at ?? now(),
                'responded_at' => now(),
                'response' => 'offered',
            ]);

            if ($request->status !== MarketplaceRequestStatus::OFFERS_RECEIVED) {
                $this->stateMachine->transitionTo($request, MarketplaceRequestStatus::OFFERS_RECEIVED, 'offer_submitted', [
                    'offer_id' => $offer->id,
                ]);
            }

            return $offer;
        });

        $this->rankingService->rankForRequest($request->fresh());
        $this->alertDispatcher->broadcastCounters($request->id, $this->broadcastService->liveCounters($request->fresh()));

        return $offer->fresh();
    }

    /**
     * Farmer accepts one pending offer: atomically closes competing offers, creates Order + OrderEvent,
     * and reveals farmer contact only if consent is granted/active.
     */
    public function acceptOffer(
        Farmer $farmer,
        Offer $offer,
        bool $grantContactRevealConsent = false,
        string $channel = 'web'
    ): MarketplaceOrder {
        return DB::transaction(function () use ($farmer, $offer, $grantContactRevealConsent, $channel): MarketplaceOrder {
            /** @var MarketplaceRequest $request */
            $request = MarketplaceRequest::withoutGlobalScopes()
                ->where('id', '=', $offer->request_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($request->farmer_id !== $farmer->id) {
                throw new DomainException('Only the owning farmer may accept an offer.');
            }

            if ($request->status !== MarketplaceRequestStatus::OFFERS_RECEIVED) {
                throw new DomainException('Offer cannot be accepted in request state ' . $request->status->value);
            }

            $freshOffer = Offer::withoutGlobalScopes()->where('id', '=', $offer->id)->lockForUpdate()->firstOrFail();
            if ($freshOffer->status !== 'pending') {
                throw new DomainException('Offer is no longer pending.');
            }

            $freshOffer->update(['status' => 'accepted']);

            // Close all competing offers and notify losing retailers
            $competingOffers = Offer::withoutGlobalScopes()
                ->where('request_id', '=', $request->id)
                ->where('id', '!=', $freshOffer->id)
                ->where('status', '=', 'pending')
                ->get();

            foreach ($competingOffers as $losingOffer) {
                $losingOffer->update(['status' => 'closed']);
                MarketplaceNotification::query()->create([
                    'recipient_type' => 'outlet',
                    'recipient_id' => $losingOffer->outlet_id,
                    'organisation_id' => $losingOffer->organisation_id,
                    'channel' => 'reverb',
                    'event_type' => 'offer_closed',
                    'title' => 'Request closed',
                    'body' => 'The farmer accepted another offer for this request.',
                    'payload_json' => [
                        'request_id' => $request->id,
                        'offer_id' => $losingOffer->id,
                    ],
                    'status' => 'queued',
                ]);
            }

            $this->stateMachine->transitionTo($request, MarketplaceRequestStatus::OFFER_ACCEPTED, 'farmer_accepted_offer', [
                'offer_id' => $freshOffer->id,
                'organisation_id' => $freshOffer->organisation_id,
            ]);

            if ($grantContactRevealConsent) {
                ConsentEvent::record(
                    tenantId: $freshOffer->organisation_id,
                    farmerId: $farmer->id,
                    scope: 'marketplace_contact_reveal',
                    action: 'granted',
                    channel: $channel,
                    source: 'farmer_offer_accept',
                    termsVersion: (string) config('betterlink.cdpa.current_policy_version', '2026.1')
                );
            }

            $hasConsent = ConsentEvent::hasActiveConsent($freshOffer->organisation_id, $farmer->id, 'marketplace_contact_reveal')
                || ConsentEvent::hasActiveConsent(null, $farmer->id, 'marketplace_contact_reveal');

            $order = MarketplaceOrder::withoutGlobalScopes()->create([
                'request_id' => $request->id,
                'offer_id' => $freshOffer->id,
                'farmer_id' => $farmer->id,
                'organisation_id' => $freshOffer->organisation_id,
                'outlet_id' => $freshOffer->outlet_id,
                'status' => 'created',
                'total_amount_minor' => $freshOffer->total_price_minor,
                'delivery_fee_minor' => $freshOffer->delivery_fee_minor,
                'currency' => $freshOffer->currency,
                'fulfilment_type' => $freshOffer->fulfilment_type === 'delivery' ? 'delivery' : 'pickup',
                'payment_method' => 'cash_on_pickup',
                'payment_status' => 'unpaid',
                'farmer_contact_revealed_at' => $hasConsent ? now() : null,
            ]);

            OrderEvent::query()->create([
                'order_id' => $order->id,
                'actor_type' => 'farmer',
                'actor_id' => $farmer->id,
                'event_type' => 'order_created',
                'from_status' => null,
                'to_status' => 'created',
                'metadata_json' => [
                    'offer_id' => $freshOffer->id,
                    'contact_revealed' => $hasConsent,
                ],
                'created_at' => now(),
            ]);

            $this->alertDispatcher->broadcastOfferStatus($freshOffer->fresh());
            foreach ($competingOffers as $losingOffer) {
                $this->alertDispatcher->broadcastOfferStatus($losingOffer->fresh());
            }
            $this->alertDispatcher->broadcastCounters($request->id, $this->broadcastService->liveCounters($request->fresh()));

            return $order;
        });
    }
}
