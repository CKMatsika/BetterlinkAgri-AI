<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Enums\CurrencyCode;
use App\Enums\MarketplaceRequestStatus;
use App\Models\DemandAggregate;
use App\Models\MarketplaceRequest;
use App\Models\Product;
use App\Models\SaleEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic M2 Demand Aggregation Engine (zero LLM).
 * Rolls up marketplace requests, anonymised sale_events, and unmet demand
 * into demand_aggregates for:
 *  - Area-wide anonymised views (organisation_id = null, with contributing_farmers_count for Agritex k >= 5 suppression)
 *  - Retailer-owned views (organisation_id = retailer tenant id)
 *  - Manufacturer brand views (organisation_id = product.manufacturer_tenant_id)
 */
final class DemandAggregationService
{
    /**
     * Deterministically aggregate a date window [periodStart, periodEnd] at the given granularity.
     * Idempotent: replaces existing rows for the exact (period_start, period_end, granularity) window.
     *
     * @return array{area_rows: int, manufacturer_rows: int, retailer_rows: int}
     */
    public function aggregatePeriod(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        string $granularity = 'weekly'
    ): array {
        $startDate = $periodStart->toDateString();
        $endDate = $periodEnd->toDateString();
        $startAt = $periodStart->copy()->startOfDay();
        $endAt = $periodEnd->copy()->endOfDay();

        return DB::transaction(function () use ($startDate, $endDate, $startAt, $endAt, $granularity): array {
            DemandAggregate::withoutGlobalScopes()
                ->whereDate('period_start', '=', $startDate)
                ->whereDate('period_end', '=', $endDate)
                ->where('granularity', '=', $granularity)
                ->delete();

            $requests = MarketplaceRequest::withoutGlobalScopes()
                ->with(['items'])
                ->whereBetween('created_at', [$startAt, $endAt])
                ->get();

            $sales = SaleEvent::withoutGlobalScopes()
                ->whereBetween('occurred_at', [$startAt, $endAt])
                ->get();

            $productManufacturers = Product::withoutGlobalScopes()
                ->whereNotNull('manufacturer_tenant_id')
                ->pluck('manufacturer_tenant_id', 'id')
                ->all();

            // 1. Area-wide anonymised cells (organisation_id = null)
            /** @var array<string, array{province_id: ?string, district_id: ?string, ward_id: ?string, product_id: ?string, category: ?string, request_ids: array<string, true>, fulfilled_ids: array<string, true>, unmet_ids: array<string, true>, farmer_ids: array<string, true>, qty: int, sales_minor: int, currency: string}> $areaBuckets */
            $areaBuckets = [];

            // 2. Manufacturer brand cells (organisation_id = manufacturer_tenant_id)
            /** @var array<string, array{org_id: string, province_id: ?string, district_id: ?string, ward_id: ?string, product_id: ?string, category: ?string, request_ids: array<string, true>, fulfilled_ids: array<string, true>, unmet_ids: array<string, true>, farmer_ids: array<string, true>, qty: int, sales_minor: int, currency: string}> $mfgBuckets */
            $mfgBuckets = [];

            // 3. Retailer cells (organisation_id = sale_event.organisation_id)
            /** @var array<string, array{org_id: string, province_id: ?string, district_id: ?string, ward_id: ?string, product_id: ?string, category: ?string, request_ids: array<string, true>, fulfilled_ids: array<string, true>, unmet_ids: array<string, true>, farmer_ids: array<string, true>, qty: int, sales_minor: int, currency: string}> $retailerBuckets */
            $retailerBuckets = [];

            $fulfilledStatuses = [
                MarketplaceRequestStatus::OFFER_ACCEPTED,
                MarketplaceRequestStatus::COMPLETED,
            ];
            $unmetStatuses = [
                MarketplaceRequestStatus::EXPIRED,
                MarketplaceRequestStatus::UPSTREAM_ESCALATED,
            ];

            foreach ($requests as $req) {
                $isFulfilled = in_array($req->status, $fulfilledStatuses, true);
                $isUnmet = in_array($req->status, $unmetStatuses, true);
                $currency = $req->currency instanceof CurrencyCode ? $req->currency->value : (string) ($req->currency ?? 'USD');

                foreach ($req->items as $item) {
                    $districtKey = (string) ($req->district_id ?? 'none');
                    $productKey = (string) ($item->product_id ?? 'none');
                    $catKey = (string) ($item->category ?? 'general');
                    $areaKey = implode('|', [$districtKey, $productKey, $catKey, $currency]);

                    if (!isset($areaBuckets[$areaKey])) {
                        $areaBuckets[$areaKey] = [
                            'province_id' => $req->province_id,
                            'district_id' => $req->district_id,
                            'ward_id' => null,
                            'product_id' => $item->product_id,
                            'category' => $item->category,
                            'request_ids' => [],
                            'fulfilled_ids' => [],
                            'unmet_ids' => [],
                            'farmer_ids' => [],
                            'qty' => 0,
                            'sales_minor' => 0,
                            'currency' => $currency,
                        ];
                    }

                    $areaBuckets[$areaKey]['request_ids'][$req->id] = true;
                    $areaBuckets[$areaKey]['farmer_ids'][$req->farmer_id] = true;
                    $areaBuckets[$areaKey]['qty'] += (int) $item->quantity_scaled;
                    if ($isFulfilled) {
                        $areaBuckets[$areaKey]['fulfilled_ids'][$req->id] = true;
                    }
                    if ($isUnmet) {
                        $areaBuckets[$areaKey]['unmet_ids'][$req->id] = true;
                    }

                    // Roll up to manufacturer if product belongs to a manufacturer tenant
                    if ($item->product_id !== null && isset($productManufacturers[$item->product_id])) {
                        $mfgOrgId = (string) $productManufacturers[$item->product_id];
                        $mfgKey = $mfgOrgId . '|' . $areaKey;
                        if (!isset($mfgBuckets[$mfgKey])) {
                            $mfgBuckets[$mfgKey] = [
                                'org_id' => $mfgOrgId,
                                'province_id' => $req->province_id,
                                'district_id' => $req->district_id,
                                'ward_id' => null,
                                'product_id' => $item->product_id,
                                'category' => $item->category,
                                'request_ids' => [],
                                'fulfilled_ids' => [],
                                'unmet_ids' => [],
                                'farmer_ids' => [],
                                'qty' => 0,
                                'sales_minor' => 0,
                                'currency' => $currency,
                            ];
                        }
                        $mfgBuckets[$mfgKey]['request_ids'][$req->id] = true;
                        $mfgBuckets[$mfgKey]['farmer_ids'][$req->farmer_id] = true;
                        $mfgBuckets[$mfgKey]['qty'] += (int) $item->quantity_scaled;
                        if ($isFulfilled) {
                            $mfgBuckets[$mfgKey]['fulfilled_ids'][$req->id] = true;
                        }
                        if ($isUnmet) {
                            $mfgBuckets[$mfgKey]['unmet_ids'][$req->id] = true;
                        }
                    }
                }
            }

            // Attach anonymised sales totals
            foreach ($sales as $sale) {
                $districtKey = (string) ($sale->district_id ?? 'none');
                $productKey = (string) ($sale->product_id ?? 'none');
                $catKey = (string) ($sale->category ?? 'general');
                $currency = $sale->currency instanceof CurrencyCode ? $sale->currency->value : (string) ($sale->currency ?? 'USD');
                $areaKey = implode('|', [$districtKey, $productKey, $catKey, $currency]);

                if (isset($areaBuckets[$areaKey])) {
                    $areaBuckets[$areaKey]['sales_minor'] += (int) $sale->total_amount_minor;
                }

                if ($sale->product_id !== null && isset($productManufacturers[$sale->product_id])) {
                    $mfgOrgId = (string) $productManufacturers[$sale->product_id];
                    $mfgKey = $mfgOrgId . '|' . $areaKey;
                    if (isset($mfgBuckets[$mfgKey])) {
                        $mfgBuckets[$mfgKey]['sales_minor'] += (int) $sale->total_amount_minor;
                    }
                }

                // Retailer-own sales aggregate
                $retKey = $sale->organisation_id . '|' . $areaKey;
                if (!isset($retailerBuckets[$retKey])) {
                    $retailerBuckets[$retKey] = [
                        'org_id' => $sale->organisation_id,
                        'province_id' => null,
                        'district_id' => $sale->district_id,
                        'ward_id' => null,
                        'product_id' => $sale->product_id,
                        'category' => $sale->category,
                        'request_ids' => [],
                        'fulfilled_ids' => [],
                        'unmet_ids' => [],
                        'farmer_ids' => [],
                        'qty' => 0,
                        'sales_minor' => 0,
                        'currency' => $currency,
                    ];
                }
                if ($sale->order_id !== null) {
                    $retailerBuckets[$retKey]['fulfilled_ids'][$sale->order_id] = true;
                    $retailerBuckets[$retKey]['request_ids'][$sale->order_id] = true;
                }
                $retailerBuckets[$retKey]['qty'] += (int) $sale->quantity_scaled;
                $retailerBuckets[$retKey]['sales_minor'] += (int) $sale->total_amount_minor;
            }

            foreach ($areaBuckets as $bucket) {
                DemandAggregate::withoutGlobalScopes()->create([
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                    'granularity' => $granularity,
                    'province_id' => $bucket['province_id'],
                    'district_id' => $bucket['district_id'],
                    'ward_id' => $bucket['ward_id'],
                    'product_id' => $bucket['product_id'],
                    'category' => $bucket['category'],
                    'organisation_id' => null,
                    'request_count' => count($bucket['request_ids']),
                    'fulfilled_count' => count($bucket['fulfilled_ids']),
                    'unmet_count' => count($bucket['unmet_ids']),
                    'contributing_farmers_count' => count($bucket['farmer_ids']),
                    'total_quantity_scaled' => $bucket['qty'],
                    'total_sales_minor' => $bucket['sales_minor'],
                    'currency' => $bucket['currency'],
                ]);
            }

            foreach ($mfgBuckets as $bucket) {
                DemandAggregate::withoutGlobalScopes()->create([
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                    'granularity' => $granularity,
                    'province_id' => $bucket['province_id'],
                    'district_id' => $bucket['district_id'],
                    'ward_id' => $bucket['ward_id'],
                    'product_id' => $bucket['product_id'],
                    'category' => $bucket['category'],
                    'organisation_id' => $bucket['org_id'],
                    'request_count' => count($bucket['request_ids']),
                    'fulfilled_count' => count($bucket['fulfilled_ids']),
                    'unmet_count' => count($bucket['unmet_ids']),
                    'contributing_farmers_count' => count($bucket['farmer_ids']),
                    'total_quantity_scaled' => $bucket['qty'],
                    'total_sales_minor' => $bucket['sales_minor'],
                    'currency' => $bucket['currency'],
                ]);
            }

            foreach ($retailerBuckets as $bucket) {
                DemandAggregate::withoutGlobalScopes()->create([
                    'period_start' => $startDate,
                    'period_end' => $endDate,
                    'granularity' => $granularity,
                    'province_id' => $bucket['province_id'],
                    'district_id' => $bucket['district_id'],
                    'ward_id' => $bucket['ward_id'],
                    'product_id' => $bucket['product_id'],
                    'category' => $bucket['category'],
                    'organisation_id' => $bucket['org_id'],
                    'request_count' => count($bucket['request_ids']),
                    'fulfilled_count' => count($bucket['fulfilled_ids']),
                    'unmet_count' => count($bucket['unmet_ids']),
                    'contributing_farmers_count' => count($bucket['farmer_ids']),
                    'total_quantity_scaled' => $bucket['qty'],
                    'total_sales_minor' => $bucket['sales_minor'],
                    'currency' => $bucket['currency'],
                ]);
            }

            return [
                'area_rows' => count($areaBuckets),
                'manufacturer_rows' => count($mfgBuckets),
                'retailer_rows' => count($retailerBuckets),
            ];
        });
    }
}
