<?php

declare(strict_types=1);

namespace App\Services\Outlets;

use App\DTOs\RankedOutletResultData;
use App\Enums\CurrencyCode;
use App\Enums\StockQuantityBand;
use App\Models\AuditLog;
use App\Models\Farmer;
use App\Models\Outlet;
use App\Models\OutletProductAuthorisation;
use App\Models\OutletStock;
use App\Models\Tenant;
use App\Models\Ward;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Section 7: Location-Aware Retailer Finder (OutletFinderService).
 *
 * Deterministic SQL/PostGIS & Spatial Service (NOT the LLM):
 * - Filter: outlet authorised for the SKU, stock not "out_of_stock", outlet active.
 * - On PostgreSQL/PostGIS: executes ST_DWithin(location_point, ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography, radius_m)
 *   and ST_Distance(location_point, ...) / 1000.0 AS distance_km using the GiST index idx_outlets_location_gist.
 * - Radius expands automatically: 25 km -> 50 km -> 100 km -> same Province.
 * - Rank by: stock confidence & freshness, then distance, then open now, then delivery, then price.
 *   Weights are tenant-configurable.
 * - Prefer a single outlet that can supply the whole basket.
 * - Never invent stock. If stock data is older than freshness limit (48 h), flag "please call to confirm".
 * - Never share exact farmer coordinates with outlets before consent.
 */
final class OutletFinderService
{
    public function __construct(
        private readonly LocationResolutionService $locationResolver
    ) {
    }

    /**
     * Find and rank the top 3 outlets for a basket of product IDs.
     *
     * @param list<string> $productIds
     * @return array{
     *   radius_used_km: string,
     *   outlets: list<RankedOutletResultData>,
     *   fallback_required: bool
     * }
     */
    public function findNearestStockedOutlets(
        Tenant $tenant,
        Farmer $farmer,
        array $productIds,
        ?float $overrideLat = null,
        ?float $overrideLng = null,
        ?Ward $overrideWard = null,
        ?string $conversationId = null
    ): array {
        $resolved = $this->locationResolver->resolveFarmerCoordinates($tenant->id, $farmer);

        $farmerLat = $overrideLat ?? ($resolved['latitude'] ?? -17.8292);
        $farmerLng = $overrideLng ?? ($resolved['longitude'] ?? 31.0522);
        $farmerWard = $overrideWard ?? ($resolved['ward'] ?? $farmer->ward);
        $farmerProvinceId = $farmerWard?->district?->province_id;

        $freshnessHours = $tenant->stockFreshnessLimitHours();
        $weights = array_merge(
            (array) config('betterlink.outlets.ranking_weights', [
                'stock_confidence' => 0.35,
                'distance' => 0.30,
                'open_now' => 0.15,
                'delivery' => 0.10,
                'price' => 0.10,
            ]),
            (array) ($tenant->outlet_ranking_weights ?? [])
        );

        /** @var list<int> $radii */
        $radii = config('betterlink.outlets.search_radii_km', [25, 50, 100]);
        $filtered = [];
        $radiusUsedLabel = '25 km';

        $isPostgis = DB::getDriverName() === 'pgsql';

        if ($isPostgis) {
            // Execute real PostGIS ST_DWithin and ST_Distance queries per expanding radius
            foreach ($radii as $radiusKm) {
                $radiusMeters = (float) ($radiusKm * 1000);
                $postgisOutlets = Outlet::withoutGlobalScopes()
                    ->with(['ward', 'district'])
                    ->select([
                        'outlets.*',
                        DB::raw(sprintf(
                            'ST_Distance(location_point, ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography) / 1000.0 AS postgis_distance_km',
                            $farmerLng,
                            $farmerLat
                        )),
                    ])
                    ->where('tenant_id', '=', $tenant->id)
                    ->where('is_active', '=', true)
                    ->whereNotNull('location_point')
                    ->whereRaw(
                        'ST_DWithin(location_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                        [$farmerLng, $farmerLat, $radiusMeters]
                    )
                    ->get();

                $evaluated = $this->evaluateCandidateOutlets(
                    $postgisOutlets,
                    $productIds,
                    $freshnessHours,
                    $farmerLat,
                    $farmerLng,
                    true
                );

                if ($evaluated !== []) {
                    $filtered = $evaluated;
                    $radiusUsedLabel = "{$radiusKm} km";
                    break;
                }
            }

            if ($filtered === [] && $farmerProvinceId !== null) {
                $provOutlets = Outlet::withoutGlobalScopes()
                    ->with(['ward', 'district'])
                    ->select([
                        'outlets.*',
                        DB::raw(sprintf(
                            'ST_Distance(location_point, ST_SetSRID(ST_MakePoint(%F, %F), 4326)::geography) / 1000.0 AS postgis_distance_km',
                            $farmerLng,
                            $farmerLat
                        )),
                    ])
                    ->where('tenant_id', '=', $tenant->id)
                    ->where('is_active', '=', true)
                    ->where('province_id', '=', $farmerProvinceId)
                    ->get();

                $evaluated = $this->evaluateCandidateOutlets(
                    $provOutlets,
                    $productIds,
                    $freshnessHours,
                    $farmerLat,
                    $farmerLng,
                    true
                );

                if ($evaluated !== []) {
                    $filtered = $evaluated;
                    $radiusUsedLabel = 'province';
                }
            }
        } else {
            // Non-PostGIS fallback (e.g., SQLite in-memory tests)
            $candidateOutlets = Outlet::withoutGlobalScopes()
                ->with(['ward', 'district'])
                ->where('tenant_id', '=', $tenant->id)
                ->where('is_active', '=', true)
                ->get();

            $evaluatedOutlets = $this->evaluateCandidateOutlets(
                $candidateOutlets,
                $productIds,
                $freshnessHours,
                $farmerLat,
                $farmerLng,
                false
            );

            foreach ($radii as $radiusKm) {
                $within = array_filter(
                    $evaluatedOutlets,
                    fn (array $item) => $item['distance_km'] <= (float) $radiusKm
                );
                if ($within !== []) {
                    $filtered = array_values($within);
                    $radiusUsedLabel = "{$radiusKm} km";
                    break;
                }
            }

            if ($filtered === [] && $farmerProvinceId !== null) {
                $inProvince = array_filter(
                    $evaluatedOutlets,
                    fn (array $item) => (string) $item['outlet']->province_id === (string) $farmerProvinceId
                );
                if ($inProvince !== []) {
                    $filtered = array_values($inProvince);
                    $radiusUsedLabel = 'province';
                }
            }
        }

        if ($filtered === []) {
            AuditLog::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversationId,
                'event_type' => 'location_lookup',
                'metadata_json' => [
                    'product_ids' => $productIds,
                    'outlets_found' => 0,
                    'fallback_required' => true,
                    'spatial_engine' => $isPostgis ? 'postgis_st_dwithin' : 'haversine_fallback',
                ],
                'created_at' => now(),
            ]);

            return [
                'radius_used_km' => 'none_in_range',
                'outlets' => [],
                'fallback_required' => true,
            ];
        }

        // Compute composite ranking score
        $results = [];
        foreach ($filtered as $item) {
            /** @var Outlet $outlet */
            $outlet = $item['outlet'];
            $distKm = (float) $item['distance_km'];
            $isOpenNow = $this->isOutletOpenNow((array) ($outlet->operating_hours_json ?? []));
            $hasDelivery = (float) $outlet->delivery_radius_km >= $distKm && (float) $outlet->delivery_radius_km > 0;

            $sStock = (float) $item['avg_stock_confidence'];
            $sDist = max(0.0, 1.0 - ($distKm / 120.0));
            $sOpen = $isOpenNow ? 1.0 : 0.2;
            $sDeliv = $hasDelivery ? 1.0 : 0.0;
            $sPrice = $item['usd_price_sum'] > 0 ? max(0.1, 1.0 / (1.0 + ($item['usd_price_sum'] / 100.0))) : 0.5;

            $wholeBasketBonus = $item['supplies_whole_basket'] ? 0.25 : 0.0;

            $compositeScore = $wholeBasketBonus
                + ((float) $weights['stock_confidence'] * $sStock)
                + ((float) $weights['distance'] * $sDist)
                + ((float) $weights['open_now'] * $sOpen)
                + ((float) $weights['delivery'] * $sDeliv)
                + ((float) $weights['price'] * $sPrice);

            $travelMinutes = max(5, (int) round(($distKm / 45.0) * 60));
            $waDigits = preg_replace('/[^\d]/', '', (string) ($outlet->whatsapp_number ?: $outlet->phone)) ?? '';

            $disclosure = $this->locationResolver->buildOutletSafeLocationDisclosure(
                tenantId: $tenant->id,
                farmer: $farmer,
                ward: $farmerWard,
                distanceKm: $distKm,
                exactLat: $farmerLat,
                exactLng: $farmerLng
            );

            $results[] = new RankedOutletResultData(
                outletId: (string) $outlet->id,
                outletName: (string) $outlet->name,
                outletType: (string) $outlet->outlet_type,
                address: (string) $outlet->address,
                wardName: (string) ($outlet->ward?->name ?? $outlet->district?->name ?? 'Zimbabwe'),
                distanceKm: round($distKm, 1),
                distanceBandLabel: $this->locationResolver->formatDistanceBand($distKm),
                estimatedTravelMinutes: $travelMinutes,
                isOpenNow: $isOpenNow,
                suppliesWholeBasket: (bool) $item['supplies_whole_basket'],
                hasDelivery: $hasDelivery,
                deliveryRadiusKm: (float) $outlet->delivery_radius_km,
                deliveryFeeAmount: (float) $outlet->delivery_fee_amount,
                deliveryFeeCurrency: (string) $outlet->delivery_fee_currency,
                phone: (string) $outlet->phone,
                whatsappLink: "https://wa.me/{$waDigits}",
                mapLink: sprintf('https://www.google.com/maps/search/?api=1&query=%.6f,%.6f', $outlet->latitude, $outlet->longitude),
                compositeRankScore: round($compositeScore, 4),
                basketStockItems: $item['basket_items'],
                farmerLocationDisclosureForOutlet: $disclosure,
            );
        }

        // Sort descending by whole-basket supply first, then compositeRankScore
        usort($results, function (RankedOutletResultData $a, RankedOutletResultData $b): int {
            if ($a->suppliesWholeBasket !== $b->suppliesWholeBasket) {
                return $b->suppliesWholeBasket <=> $a->suppliesWholeBasket;
            }

            return $b->compositeRankScore <=> $a->compositeRankScore;
        });

        $top3 = array_slice($results, 0, 3);

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversationId,
            'event_type' => 'location_lookup',
            'metadata_json' => [
                'product_ids' => $productIds,
                'radius_used' => $radiusUsedLabel,
                'top_outlet_ids' => array_map(fn (RankedOutletResultData $r) => $r->outletId, $top3),
                'spatial_engine' => $isPostgis ? 'postgis_st_dwithin' : 'haversine_fallback',
            ],
            'created_at' => now(),
        ]);

        return [
            'radius_used_km' => $radiusUsedLabel,
            'outlets' => $top3,
            'fallback_required' => false,
        ];
    }

    /**
     * @param Collection<int, Outlet> $outlets
     * @param list<string> $productIds
     * @return list<array<string, mixed>>
     */
    private function evaluateCandidateOutlets(
        Collection $outlets,
        array $productIds,
        int $freshnessHours,
        float $farmerLat,
        float $farmerLng,
        bool $usePostgisDistanceColumn
    ): array {
        $evaluated = [];

        foreach ($outlets as $outlet) {
            $authorisedProductIds = OutletProductAuthorisation::withoutGlobalScopes()
                ->where('outlet_id', '=', $outlet->id)
                ->whereIn('product_id', $productIds)
                ->where('is_authorised', '=', true)
                ->pluck('product_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            if ($authorisedProductIds === []) {
                continue;
            }

            $stockRows = OutletStock::withoutGlobalScopes()
                ->with('product')
                ->where('outlet_id', '=', $outlet->id)
                ->whereIn('product_id', $authorisedProductIds)
                ->where('quantity_band', '!=', StockQuantityBand::OUT_OF_STOCK->value)
                ->get();

            if ($stockRows->isEmpty()) {
                continue;
            }

            $distanceKm = ($usePostgisDistanceColumn && $outlet->getAttribute('postgis_distance_km') !== null)
                ? (float) $outlet->getAttribute('postgis_distance_km')
                : $this->haversineDistanceKm(
                    $farmerLat,
                    $farmerLng,
                    (float) $outlet->latitude,
                    (float) $outlet->longitude
                );

            $groupedByProduct = $stockRows->groupBy('product_id');
            $basketItems = [];
            $stockScoreAccum = 0.0;
            $usdPricesSum = 0.0;

            foreach ($groupedByProduct as $prodId => $rows) {
                $firstRow = $rows->first();
                if ($firstRow === null) {
                    continue;
                }

                $usdRow = $rows->firstWhere('currency', CurrencyCode::USD);
                $zigRow = $rows->firstWhere('currency', CurrencyCode::ZIG);

                $isFresh = $firstRow->isFresh($freshnessHours);
                $bandScore = $firstRow->quantity_band->confidenceScore();
                $freshnessMultiplier = $isFresh ? 1.0 : 0.45;
                $stockScoreAccum += ($bandScore * $freshnessMultiplier);

                $priceUsd = $usdRow !== null ? (float) $usdRow->price : null;
                $priceZig = $zigRow !== null ? (float) $zigRow->price : null;
                if ($priceUsd !== null) {
                    $usdPricesSum += $priceUsd;
                }

                $basketItems[] = [
                    'product_id' => (string) $prodId,
                    'sku' => (string) ($firstRow->product?->sku ?? ''),
                    'product_name' => (string) ($firstRow->product?->name ?? 'Product'),
                    'pack_size' => (string) $firstRow->pack_size,
                    'quantity_band' => $firstRow->quantity_band->value,
                    'price_usd' => $priceUsd,
                    'price_zig' => $priceZig,
                    'is_fresh' => $isFresh,
                    'last_updated_human' => $firstRow->last_updated_at?->diffForHumans() ?? 'unknown',
                    'stale_warning' => $isFresh ? null : 'Stock last updated >48h ago — please call to confirm.',
                ];
            }

            $matchedCount = count($basketItems);
            if ($matchedCount === 0) {
                continue;
            }

            $suppliesWholeBasket = $matchedCount >= count(array_unique($productIds));
            $avgStockConfidence = $stockScoreAccum / $matchedCount;

            $evaluated[] = [
                'outlet' => $outlet,
                'distance_km' => $distanceKm,
                'supplies_whole_basket' => $suppliesWholeBasket,
                'avg_stock_confidence' => $avgStockConfidence,
                'usd_price_sum' => $usdPricesSum,
                'basket_items' => $basketItems,
            ];
        }

        return $evaluated;
    }

    /**
     * Format the top 3 outlets into a concise WhatsApp message + rich card.
     *
     * @param list<RankedOutletResultData> $outlets
     */
    public function formatOutletResultsForWhatsApp(array $outlets, string $radiusUsed): string
    {
        if ($outlets === []) {
            return "We could not find an authorised outlet with live stock within 100 km of your ward. Reply:\n"
                . "*1.* Request delivery from our nearest provincial depot\n"
                . "*2.* Request a callback from a field sales representative";
        }

        $lines = ["📍 *Nearest Stocked Outlets (within {$radiusUsed}):*"];

        foreach ($outlets as $idx => $o) {
            $num = $idx + 1;
            $openBadge = $o->isOpenNow ? '🟢 Open now' : '🕒 Closed now';
            $basketBadge = $o->suppliesWholeBasket ? '✅ Full basket in stock' : '⚠️ Partial basket';
            $delivText = $o->hasDelivery
                ? "🚚 Delivers ({$o->deliveryFeeCurrency} {$o->deliveryFeeAmount})"
                : 'Store pickup';

            $itemLines = [];
            foreach ($o->basketStockItems as $item) {
                $pricePart = '';
                if ($item['price_usd'] !== null) {
                    $pricePart .= sprintf('US$%.2f', $item['price_usd']);
                }
                if ($item['price_zig'] !== null) {
                    $pricePart .= ($pricePart !== '' ? ' / ' : '') . sprintf('ZiG %.2f', $item['price_zig']);
                }
                $freshNote = $item['stale_warning'] !== null
                    ? " ⚠️ _{$item['stale_warning']}_"
                    : " _(updated {$item['last_updated_human']})_";
                $itemLines[] = "   • {$item['product_name']} ({$item['pack_size']}): {$pricePart}{$freshNote}";
            }

            $lines[] = "*{$num}. {$o->outletName}* — {$o->distanceKm} km (~{$o->estimatedTravelMinutes} mins)\n"
                . "   {$openBadge} | {$basketBadge} | {$delivText}\n"
                . implode("\n", $itemLines) . "\n"
                . "   📞 {$o->phone} | 🗺️ {$o->mapLink}";
        }

        $lines[] = "\nReply *HOLD 1*, *HOLD 2*, or *HOLD 3* to reserve this stock for pickup (30-min confirmation guarantee), or *REP* to speak to a representative.";

        return implode("\n\n", $lines);
    }

    private function haversineDistanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
            * sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * @param array<string, string> $hoursConfig
     */
    private function isOutletOpenNow(array $hoursConfig): bool
    {
        $nowHarare = now('Africa/Harare');
        $dayOfWeek = (int) $nowHarare->format('N'); // 1 (Mon) .. 7 (Sun)
        $hour = (int) $nowHarare->format('G');

        if ($dayOfWeek === 7) {
            return ($hoursConfig['sun'] ?? 'closed') !== 'closed';
        }

        if ($dayOfWeek === 6) {
            return $hour >= 7 && $hour < 13;
        }

        return $hour >= 7 && $hour < 17;
    }
}
