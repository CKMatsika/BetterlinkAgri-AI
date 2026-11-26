<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Models\Outlet;
use App\Models\OutletStock;
use App\Models\Product;
use App\Models\ProductTarget;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic SubstituteResolverService (zero LLM).
 * Finds registered, active, non-expired, non-restricted substitute products sharing
 * the same active ingredient OR the same (crop_id, target_code) registration,
 * and confirmed in stock within the nearest spatial ring.
 * Always requires explicit farmer confirmation.
 */
final class SubstituteResolverService
{
    /**
     * @return list<array{
     *   product_id: string,
     *   product_name: string,
     *   active_ingredient: ?string,
     *   match_reason: string,
     *   in_stock_outlet_count: int,
     *   min_price_minor: ?int,
     *   currency: string,
     *   requires_farmer_confirmation: bool
     * }>
     */
    public function findSafeInStockSubstitutes(
        Product $originalProduct,
        float $lat,
        float $lng,
        ?string $cropId = null,
        ?string $targetCode = null,
        ?int $radiusKm = null
    ): array {
        /** @var list<int> $rings */
        $rings = (array) config('betterlink.marketplace.rings_km', [10, 25, 50]);
        $searchRadiusKm = $radiusKm ?? (int) (end($rings) ?: 50);

        $nearbyOutletIds = $this->findActiveOutletIdsInRadius($lat, $lng, $searchRadiusKm);
        if ($nearbyOutletIds === []) {
            return [];
        }

        $candidateIdsByReason = [];

        // 1. Same active ingredient match (case-insensitive)
        $activeIngredient = trim((string) ($originalProduct->active_ingredient ?? ''));
        if ($activeIngredient !== '') {
            $sameAiIds = Product::withoutGlobalScopes()
                ->where('id', '!=', $originalProduct->id)
                ->whereRaw('LOWER(active_ingredient) = ?', [strtolower($activeIngredient)])
                ->pluck('id')
                ->all();
            foreach ($sameAiIds as $pid) {
                $candidateIdsByReason[(string) $pid] = 'same_active_ingredient';
            }
        }

        // 2. Same crop + target registration match
        if ($cropId !== null && $targetCode !== null) {
            $sameTargetIds = ProductTarget::withoutGlobalScopes()
                ->where('crop_id', '=', $cropId)
                ->where('target_code', '=', $targetCode)
                ->where('product_id', '!=', $originalProduct->id)
                ->pluck('product_id')
                ->all();
            foreach ($sameTargetIds as $pid) {
                $candidateIdsByReason[(string) $pid] ??= 'same_crop_and_target_registration';
            }
        }

        if ($candidateIdsByReason === []) {
            return [];
        }

        // Apply strict safety guardrails: active, not restricted use, not expired
        $safeProducts = Product::withoutGlobalScopes()
            ->whereIn('id', array_keys($candidateIdsByReason))
            ->where('is_active', '=', true)
            ->where('is_restricted_use', '=', false)
            ->where(function ($q): void {
                $q->whereNull('registration_expiry')
                    ->orWhereDate('registration_expiry', '>=', now()->toDateString());
            })
            ->get();

        $results = [];
        foreach ($safeProducts as $product) {
            $stocks = OutletStock::withoutGlobalScopes()
                ->whereIn('outlet_id', $nearbyOutletIds)
                ->where('product_id', '=', $product->id)
                ->where('quantity_band', '!=', 'out_of_stock')
                ->get();

            if ($stocks->isEmpty()) {
                continue;
            }

            $minPrice = $stocks->min('price');
            $currency = (string) ($stocks->first()?->currency instanceof \BackedEnum
                ? $stocks->first()->currency->value
                : ($stocks->first()?->currency ?? 'USD'));

            $results[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'active_ingredient' => $product->active_ingredient,
                'match_reason' => $candidateIdsByReason[$product->id],
                'in_stock_outlet_count' => $stocks->pluck('outlet_id')->unique()->count(),
                'min_price_minor' => $minPrice !== null ? (int) round(((float) $minPrice) * 100) : null,
                'currency' => $currency,
                'requires_farmer_confirmation' => true,
            ];
        }

        usort($results, static fn (array $a, array $b): int => ($a['min_price_minor'] ?? PHP_INT_MAX) <=> ($b['min_price_minor'] ?? PHP_INT_MAX));

        return $results;
    }

    /**
     * Verify a single substitute product passes all safety guardrails.
     */
    public function isSafeRegisteredProduct(Product $product): bool
    {
        if (!$product->is_active || $product->is_restricted_use) {
            return false;
        }

        if ($product->registration_expiry !== null && $product->registration_expiry->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function findActiveOutletIdsInRadius(float $lat, float $lng, int $radiusKm): array
    {
        $query = Outlet::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', '=', true);

        if (DB::getDriverName() === 'pgsql') {
            return $query
                ->whereNotNull('location_point')
                ->whereRaw(
                    'ST_DWithin(location_point, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                    [$lng, $lat, (float) ($radiusKm * 1000)]
                )
                ->pluck('id')
                ->all();
        }

        return $query->get()->filter(function (Outlet $o) use ($lat, $lng, $radiusKm): bool {
            $dLat = deg2rad((float) $o->latitude - $lat);
            $dLon = deg2rad((float) $o->longitude - $lng);
            $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat)) * cos(deg2rad((float) $o->latitude)) * sin($dLon / 2) ** 2;

            return (6371.0 * 2 * asin(min(1.0, sqrt($a)))) <= $radiusKm;
        })->pluck('id')->values()->all();
    }
}
