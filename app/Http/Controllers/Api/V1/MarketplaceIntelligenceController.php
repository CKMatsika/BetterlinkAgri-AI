<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\MarketplaceRequestStatus;
use App\Enums\OrganisationType;
use App\Models\DemandAggregate;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceRequest;
use App\Models\Product;
use App\Models\StockSignal;
use App\Models\User;
use App\Services\Marketplace\MarketplaceOfferAndOrderService;
use App\Support\MarketplaceContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * M2 Supply-Chain Intelligence & Order Completion API.
 * Strictly enforces the Marketplace visibility matrix:
 *  - Agritex (government_office): area-wide anonymised cells with k >= 5 farmers only.
 *  - Manufacturer: own-brand demand aggregates & stock signals only (never individual requests or competing retail offers).
 *  - Distributor/Wholesaler: own demand aggregates, unmet stock signals, and UPSTREAM_ESCALATED requests (no farmer PII).
 *  - Retailer: own organisation's demand aggregates, stock signals, and order completion.
 *  - Bank: 403 Forbidden on demand & stock signal intelligence.
 */
final class MarketplaceIntelligenceController
{
    public function __construct(
        private readonly MarketplaceContext $marketplaceContext,
        private readonly MarketplaceOfferAndOrderService $offerAndOrderService
    ) {
    }

    public function completeOrder(Request $request, string $orderId): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        if ($user === null || $user->tenant === null) {
            return response()->json(['error' => 'Authenticated organisation user required.'], 401);
        }

        $order = MarketplaceOrder::withoutGlobalScopes()->find($orderId);
        if ($order === null) {
            return response()->json(['error' => 'Order not found.'], 404);
        }

        if ($order->organisation_id !== $user->tenant_id) {
            return response()->json(['error' => 'Forbidden: order belongs to another organisation.'], 403);
        }

        $completed = $this->offerAndOrderService->completeOrder($order, $user->id);

        return response()->json([
            'order' => [
                'id' => $completed->id,
                'request_id' => $completed->request_id,
                'status' => $completed->status,
                'payment_status' => $completed->payment_status,
                'total_amount_minor' => $completed->total_amount_minor,
                'completed_at' => $completed->completed_at?->toIso8601String(),
            ],
        ]);
    }

    public function demand(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $org = $user?->tenant;
        if ($user === null || $org === null || $org->organisation_type === null) {
            return response()->json(['error' => 'Authenticated marketplace organisation required.'], 403);
        }

        if ($org->organisation_type === OrganisationType::BANK) {
            return response()->json(['error' => 'Forbidden: banks cannot access marketplace demand intelligence.'], 403);
        }

        $assignedDistrictIds = array_values(array_filter(
            (array) ($request->query('assigned_district_ids', [])),
            static fn ($v): bool => is_string($v) && $v !== ''
        ));

        $this->marketplaceContext->setOrganisationActor(
            organisationId: $org->id,
            organisationType: $org->organisation_type,
            userId: $user->id,
            assignedDistrictIds: $assignedDistrictIds
        );

        try {
            $query = DemandAggregate::query()
                ->orderByDesc('period_start')
                ->orderByDesc('request_count');

            if ($org->organisation_type === OrganisationType::GOVERNMENT_OFFICE) {
                $query->whereNull('organisation_id');
            }

            if ($request->filled('granularity')) {
                $query->where('granularity', '=', (string) $request->query('granularity'));
            }
            if ($request->filled('district_id')) {
                $query->where('district_id', '=', (string) $request->query('district_id'));
            }
            if ($request->filled('product_id')) {
                $query->where('product_id', '=', (string) $request->query('product_id'));
            }

            $rows = $query->limit(200)->get()->map(static fn (DemandAggregate $agg): array => [
                'id' => $agg->id,
                'period_start' => $agg->period_start?->toDateString(),
                'period_end' => $agg->period_end?->toDateString(),
                'granularity' => $agg->granularity,
                'province_id' => $agg->province_id,
                'district_id' => $agg->district_id,
                'product_id' => $agg->product_id,
                'category' => $agg->category,
                'organisation_id' => $agg->organisation_id,
                'request_count' => $agg->request_count,
                'fulfilled_count' => $agg->fulfilled_count,
                'unmet_count' => $agg->unmet_count,
                'contributing_farmers_count' => $agg->contributing_farmers_count,
                'total_quantity_scaled' => $agg->total_quantity_scaled,
                'total_sales_minor' => $agg->total_sales_minor,
                'currency' => $agg->currency instanceof \BackedEnum ? $agg->currency->value : (string) $agg->currency,
            ])->values()->all();

            return response()->json([
                'actor_organisation_type' => $org->organisation_type->value,
                'aggregates' => $rows,
            ]);
        } finally {
            $this->marketplaceContext->reset();
        }
    }

    public function stockSignals(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $org = $user?->tenant;
        if ($user === null || $org === null || $org->organisation_type === null) {
            return response()->json(['error' => 'Authenticated marketplace organisation required.'], 403);
        }

        if ($org->organisation_type === OrganisationType::BANK) {
            return response()->json(['error' => 'Forbidden: banks cannot access stock signals.'], 403);
        }

        $query = StockSignal::withoutGlobalScopes()->orderByDesc('occurred_at');

        if ($org->organisation_type === OrganisationType::MANUFACTURER) {
            $brandProductIds = Product::withoutGlobalScopes()
                ->where('manufacturer_tenant_id', '=', $org->id)
                ->pluck('id')
                ->all();

            if ($brandProductIds === []) {
                return response()->json([
                    'actor_organisation_type' => $org->organisation_type->value,
                    'signals' => [],
                ]);
            }

            $query->whereIn('product_id', $brandProductIds);
        } elseif (in_array($org->organisation_type, [OrganisationType::RETAILER, OrganisationType::INDEPENDENT_RETAILER], true)) {
            $query->where('organisation_id', '=', $org->id);
        } elseif ($org->organisation_type === OrganisationType::DISTRIBUTOR) {
            $query->whereIn('signal_type', ['out_of_stock_response', 'ring_exhausted', 'low_stock']);
        } elseif ($org->organisation_type === OrganisationType::GOVERNMENT_OFFICE) {
            // Agritex: only signals in (district_id, product_id) cells meeting k >= 5 farmers in DemandAggregate
            $allowedPairs = DemandAggregate::withoutGlobalScopes()
                ->whereNull('organisation_id')
                ->where('contributing_farmers_count', '>=', 5)
                ->get(['district_id', 'product_id'])
                ->map(static fn (DemandAggregate $a): string => (string) $a->district_id . '|' . (string) $a->product_id)
                ->unique()
                ->flip()
                ->all();

            $signals = $query->limit(200)->get()
                ->filter(static fn (StockSignal $s): bool => isset($allowedPairs[(string) $s->district_id . '|' . (string) $s->product_id]))
                ->map(static fn (StockSignal $s): array => [
                    'district_id' => $s->district_id,
                    'product_id' => $s->product_id,
                    'category' => $s->category,
                    'signal_type' => $s->signal_type,
                    'quantity_requested_scaled' => $s->quantity_requested_scaled,
                    'occurred_at' => $s->occurred_at?->toIso8601String(),
                ])
                ->values()
                ->all();

            return response()->json([
                'actor_organisation_type' => $org->organisation_type->value,
                'signals' => $signals,
            ]);
        }

        $signals = $query->limit(200)->get()->map(static fn (StockSignal $s): array => [
            'id' => $s->id,
            'district_id' => $s->district_id,
            'product_id' => $s->product_id,
            'category' => $s->category,
            'signal_type' => $s->signal_type,
            'quantity_requested_scaled' => $s->quantity_requested_scaled,
            'occurred_at' => $s->occurred_at?->toIso8601String(),
        ])->values()->all();

        return response()->json([
            'actor_organisation_type' => $org->organisation_type->value,
            'signals' => $signals,
        ]);
    }

    public function upstreamRequests(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();
        $org = $user?->tenant;
        if ($user === null || $org === null || $org->organisation_type === null) {
            return response()->json(['error' => 'Authenticated marketplace organisation required.'], 403);
        }

        if ($org->organisation_type !== OrganisationType::DISTRIBUTOR) {
            return response()->json(['error' => 'Forbidden: only distributors/wholesalers may view upstream-escalated requests.'], 403);
        }

        $this->marketplaceContext->setOrganisationActor(
            organisationId: $org->id,
            organisationType: $org->organisation_type,
            userId: $user->id
        );

        try {
            $requests = MarketplaceRequest::query()
                ->with(['items'])
                ->where('status', '=', MarketplaceRequestStatus::UPSTREAM_ESCALATED->value)
                ->orderByDesc('created_at')
                ->limit(100)
                ->get()
                ->map(static fn (MarketplaceRequest $r): array => [
                    'id' => $r->id,
                    'status' => $r->status->value,
                    'district_id' => $r->district_id,
                    'delivery_preference' => $r->delivery_preference,
                    'needed_by_date' => $r->needed_by_date?->toDateString(),
                    'items' => $r->items->map(static fn ($i): array => [
                        'product_id' => $i->product_id,
                        'item_name' => $i->item_name,
                        'quantity_scaled' => $i->quantity_scaled,
                        'unit' => $i->unit,
                    ])->values()->all(),
                ])
                ->values()
                ->all();

            return response()->json([
                'upstream_requests' => $requests,
            ]);
        } finally {
            $this->marketplaceContext->reset();
        }
    }
}
