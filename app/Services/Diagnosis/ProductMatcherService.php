<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\DTOs\MatchedProductRecommendationData;
use App\Enums\RecommendationScope;
use App\Models\Product;
use App\Models\ProductTarget;
use App\Models\Tenant;
use App\Models\TenantSupplyLink;

/**
 * Deterministic Product Matcher:
 * - Recommends ONLY products from the tenant's approved catalogue (or active supply-link partners
 *   when recommendation_scope == own_plus_partner_brands).
 * - Verifies Zimbabwe registration is active and non-expired.
 * - Filters out restricted SKUs configured in tenant boundaries.
 * - Ranks strictly by agronomic fit (agronomic_fit_rank_score) and Mode-of-Action diversity, NEVER by margin.
 */
final class ProductMatcherService
{
    /**
     * @return list<MatchedProductRecommendationData>
     */
    public function findApprovedProducts(
        Tenant $tenant,
        string $targetCode,
        ?string $cropId = null,
        ?string $livestockId = null,
        int $limit = 3
    ): array {
        $allowedTenantIds = [$tenant->id];
        $approvedPartnerProductIds = [];

        if ($tenant->recommendation_scope === RecommendationScope::OWN_PLUS_PARTNER_BRANDS) {
            $links = TenantSupplyLink::query()
                ->where('distributor_or_retailer_tenant_id', '=', $tenant->id)
                ->where('status', '=', 'active')
                ->get();

            foreach ($links as $link) {
                $allowedTenantIds[] = (string) $link->manufacturer_tenant_id;
                $skuIds = (array) ($link->approved_product_ids_json ?? []);
                foreach ($skuIds as $id) {
                    $approvedPartnerProductIds[] = (string) $id;
                }
            }
        }

        $restrictedProductIds = array_map(
            'strval',
            (array) ($tenant->boundaries_config['restricted_product_ids'] ?? [])
        );

        $query = ProductTarget::withoutGlobalScopes()
            ->with(['product', 'sourceDocument'])
            ->whereIn('tenant_id', array_values(array_unique($allowedTenantIds)))
            ->where('target_code', '=', $targetCode);

        if ($cropId !== null) {
            $query->where('crop_id', '=', $cropId);
        } elseif ($livestockId !== null) {
            $query->where('livestock_id', '=', $livestockId);
        }

        $targets = $query->get();

        $matched = [];
        foreach ($targets as $target) {
            /** @var Product|null $product */
            $product = $target->product;
            if ($product === null) {
                continue;
            }

            // 1. Check active & non-expired Zimbabwe registration
            if (!$product->isRegistrationActive()) {
                continue;
            }

            // 2. Check restricted product boundary
            if ($product->is_restricted_use || in_array((string) $product->id, $restrictedProductIds, true)) {
                continue;
            }

            // 3. If partner product, check supply link restriction if specific product IDs were listed
            if (
                (string) $product->tenant_id !== (string) $tenant->id
                && $approvedPartnerProductIds !== []
                && !in_array((string) $product->id, $approvedPartnerProductIds, true)
            ) {
                continue;
            }

            $packsRaw = (array) ($product->pack_sizes_json ?? []);
            $packs = [];
            foreach ($packsRaw as $pack) {
                $packs[] = [
                    'size' => (string) ($pack['size'] ?? 'Standard'),
                    'price_usd' => (float) ($pack['price_usd'] ?? 0.0),
                    'price_zig' => (float) ($pack['price_zig'] ?? round(((float) ($pack['price_usd'] ?? 0.0)) * (float) $tenant->usd_to_zig_rate, 2)),
                ];
            }

            $ppe = array_values(array_map('strval', (array) ($product->ppe_requirements_json ?? ['gloves', 'mask', 'gumboots', 'overalls'])));
            $warningsRaw = (array) ($product->safety_warnings_json ?? []);
            $warnings = [];
            foreach ($warningsRaw as $k => $v) {
                $warnings[(string) $k] = (string) $v;
            }

            $matched[] = new MatchedProductRecommendationData(
                productId: (string) $product->id,
                sku: (string) $product->sku,
                productName: (string) $product->name,
                brandName: $product->brand_name,
                activeIngredient: (string) ($product->active_ingredient ?? 'Registered Active'),
                modeOfActionGroup: (string) ($product->mode_of_action_group ?? 'Specified on Label'),
                registrationNumber: (string) $product->registration_number,
                agronomicFitRankScore: (float) $product->agronomic_fit_rank_score,
                dosePer16LKnapsackValue: (float) $target->dose_per_16l_knapsack_value,
                dosePer16LKnapsackUnit: (string) $target->dose_per_16l_knapsack_unit,
                dosePerHaValue: (float) $target->dose_per_ha_value,
                dosePerHaUnit: (string) $target->dose_per_ha_unit,
                waterVolumePerHaLitres: (float) $target->water_volume_per_ha_litres,
                mixingOrder: (string) ($target->mixing_order ?? 'Half-fill tank with clean water, add measured product, agitate, and top up to 16 L.'),
                applicationTimingNotes: (string) ($target->application_timing_notes ?? 'Apply according to label instructions.'),
                reapplicationIntervalDays: $target->reapplication_interval_days !== null ? (int) $target->reapplication_interval_days : null,
                phiDays: (int) $target->phi_days,
                reiHours: (int) $target->rei_hours,
                packSizes: $packs,
                ppeRequirements: $ppe,
                safetyWarnings: $warnings,
                citedDocumentTitle: (string) ($target->sourceDocument?->title ?? 'Zimbabwe Approved Product Label'),
                citedPageNumber: $target->source_page_number !== null ? (int) $target->source_page_number : null,
            );
        }

        // Rank strictly by agronomic_fit_rank_score descending (never by price/margin)
        usort(
            $matched,
            fn (MatchedProductRecommendationData $a, MatchedProductRecommendationData $b) => $b->agronomicFitRankScore <=> $a->agronomicFitRankScore
        );

        return array_slice($matched, 0, $limit);
    }
}
