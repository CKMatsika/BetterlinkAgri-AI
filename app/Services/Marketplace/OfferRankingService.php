<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\OutletStock;
use App\Models\Rating;
use App\Models\RequestRecipient;

/**
 * Pure deterministic Offer Ranking Service (no LLM, no pay-to-rank).
 * Scores pending offers on a 0..10000 integer scale and records a plain-language explanation.
 */
final class OfferRankingService
{
    /**
     * @return list<Offer>
     */
    public function rankForRequest(MarketplaceRequest $request): array
    {
        $offers = Offer::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->where('status', '=', 'pending')
            ->get();

        if ($offers->isEmpty()) {
            return [];
        }

        $weights = (array) config('betterlink.marketplace.ranking_weights', [
            'price' => 35,
            'distance' => 25,
            'rating' => 20,
            'stock_confidence' => 10,
            'delivery' => 10,
        ]);

        $effectiveTotals = $offers->mapWithKeys(fn (Offer $o) => [
            $o->id => (int) $o->total_price_minor + (int) $o->delivery_fee_minor,
        ]);

        $minTotal = max(1, (int) $effectiveTotals->min());
        $maxTotal = max($minTotal, (int) $effectiveTotals->max());
        $priceSpread = $maxTotal - $minTotal;

        $recipientsByOutlet = RequestRecipient::withoutGlobalScopes()
            ->where('request_id', '=', $request->id)
            ->get()
            ->keyBy('outlet_id');

        $productIds = $request->items()->whereNotNull('product_id')->pluck('product_id')->all();
        $freshnessCutoff = now()->subHours((int) config('betterlink.outlets.stock_freshness_limit_hours', 48));

        $scored = [];

        foreach ($offers as $offer) {
            // 1. Price score (0..100)
            $totalMinor = (int) $effectiveTotals[$offer->id];
            $pricePct = $priceSpread === 0
                ? 100
                : (int) round((($maxTotal - $totalMinor) / $priceSpread) * 100);

            // 2. Distance score (0..100) from ring/distance_band
            $recipient = $recipientsByOutlet->get($offer->outlet_id);
            $ring = $recipient ? (int) $recipient->ring_number : 3;
            $distancePct = match ($ring) {
                1 => 100,
                2 => 65,
                default => 35,
            };

            // 3. Seller rating score (0..100; unrated defaults to 70 = 3.5/5)
            $avgRating = Rating::query()
                ->where('ratee_type', '=', 'retailer')
                ->where('ratee_id', '=', $offer->organisation_id)
                ->avg('score');
            $ratingPct = $avgRating !== null ? (int) round(((float) $avgRating / 5.0) * 100) : 70;

            // 4. Stock confidence score (0..100)
            $hasFreshStock = $productIds !== [] && OutletStock::withoutGlobalScopes()
                ->where('outlet_id', '=', $offer->outlet_id)
                ->whereIn('product_id', $productIds)
                ->where('quantity_band', '!=', 'out_of_stock')
                ->where('last_updated_at', '>=', $freshnessCutoff)
                ->exists();
            $stockPct = $hasFreshStock ? 100 : 60;

            // 5. Delivery preference match (0..100)
            $pref = (string) ($request->delivery_preference ?: 'either');
            $deliveryPct = match (true) {
                $pref === 'either' => 100,
                $offer->fulfilment_type === 'both' => 100,
                $offer->fulfilment_type === $pref => 100,
                default => 40,
            };

            $scoreScaled =
                ($pricePct * (int) ($weights['price'] ?? 35))
                + ($distancePct * (int) ($weights['distance'] ?? 25))
                + ($ratingPct * (int) ($weights['rating'] ?? 20))
                + ($stockPct * (int) ($weights['stock_confidence'] ?? 10))
                + ($deliveryPct * (int) ($weights['delivery'] ?? 10));

            $explanation = [
                'price_score_pct' => $pricePct,
                'distance_ring' => $ring,
                'distance_score_pct' => $distancePct,
                'rating_score_pct' => $ratingPct,
                'stock_confidence_pct' => $stockPct,
                'delivery_match_pct' => $deliveryPct,
                'effective_total_minor' => $totalMinor,
                'summary' => sprintf(
                    'Ring %d shop, total %s %s, %s.',
                    $ring,
                    $offer->currency instanceof \BackedEnum ? $offer->currency->value : (string) $offer->currency,
                    number_format($totalMinor / 100, 2),
                    $hasFreshStock ? 'fresh verified stock' : 'standard stock confirmation'
                ),
            ];

            $offer->update([
                'rank_score_scaled' => $scoreScaled,
                'rank_explanation_json' => $explanation,
            ]);

            $scored[] = $offer->fresh();
        }

        usort($scored, static function (Offer $a, Offer $b): int {
            $cmp = ((int) $b->rank_score_scaled) <=> ((int) $a->rank_score_scaled);
            if ($cmp !== 0) {
                return $cmp;
            }
            $priceCmp = ((int) $a->total_price_minor + (int) $a->delivery_fee_minor)
                <=> ((int) $b->total_price_minor + (int) $b->delivery_fee_minor);

            return $priceCmp !== 0 ? $priceCmp : strcmp($a->id, $b->id);
        });

        return $scored;
    }
}
