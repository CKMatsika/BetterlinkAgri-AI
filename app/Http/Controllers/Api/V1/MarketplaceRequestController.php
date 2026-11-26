<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\CurrencyCode;
use App\Enums\MarketplaceRequestStatus;
use App\Models\Farmer;
use App\Models\MarketplaceRequest;
use App\Models\Offer;
use App\Models\RequestItem;
use App\Models\RequestRecipient;
use App\Services\Marketplace\MarketplaceBroadcastService;
use App\Services\Marketplace\MarketplaceOfferAndOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MarketplaceRequestController
{
    public function __construct(
        private readonly MarketplaceBroadcastService $broadcastService,
        private readonly MarketplaceOfferAndOrderService $offerAndOrderService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');

        $requests = MarketplaceRequest::withoutGlobalScopes()
            ->with('items')
            ->where('farmer_id', '=', $farmerId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return response()->json(['requests' => $requests]);
    }

    public function store(Request $request): JsonResponse
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        $tenantId = $request->attributes->get('planner_tenant_id');
        $farmer = Farmer::query()->findOrFail($farmerId);

        $validated = $request->validate([
            'request_type' => ['nullable', 'string', 'in:stock,booking,finance'],
            'source' => ['nullable', 'string', 'in:ai_conversation,app,web,whatsapp,ussd,plan_schedule'],
            'approx_latitude' => ['required', 'numeric', 'between:-90,90'],
            'approx_longitude' => ['required', 'numeric', 'between:-180,180'],
            'exact_location' => ['nullable', 'string', 'max:500'],
            'delivery_preference' => ['nullable', 'string', 'in:pickup,delivery,either'],
            'needed_by_date' => ['nullable', 'date'],
            'max_budget_minor' => ['nullable', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'in:USD,ZiG'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'uuid'],
            'items.*.category' => ['nullable', 'string', 'max:60'],
            'items.*.item_name' => ['required', 'string', 'max:200'],
            'items.*.quantity_scaled' => ['required', 'integer', 'min:1'],
            'items.*.unit' => ['required', 'string', 'max:30'],
        ]);

        $marketRequest = DB::transaction(function () use ($validated, $farmer, $tenantId): MarketplaceRequest {
            $req = MarketplaceRequest::withoutGlobalScopes()->create([
                'farmer_id' => $farmer->id,
                'origin_organisation_id' => $tenantId,
                'request_type' => (string) ($validated['request_type'] ?? 'stock'),
                'status' => MarketplaceRequestStatus::DRAFT,
                'source' => (string) ($validated['source'] ?? 'web'),
                'approx_latitude' => (float) $validated['approx_latitude'],
                'approx_longitude' => (float) $validated['approx_longitude'],
                'exact_location_encrypted' => $validated['exact_location'] ?? null,
                'farmer_phone_encrypted' => $farmer->phone_encrypted,
                'delivery_preference' => (string) ($validated['delivery_preference'] ?? 'either'),
                'needed_by_date' => $validated['needed_by_date'] ?? null,
                'max_budget_minor' => $validated['max_budget_minor'] ?? null,
                'currency' => CurrencyCode::tryFrom((string) ($validated['currency'] ?? 'USD')) ?? CurrencyCode::USD,
            ]);

            if (DB::getDriverName() === 'pgsql') {
                DB::update(
                    'UPDATE requests SET approx_location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                    [(float) $validated['approx_longitude'], (float) $validated['approx_latitude'], $req->id]
                );
            }

            foreach ($validated['items'] as $item) {
                RequestItem::query()->create([
                    'request_id' => $req->id,
                    'product_id' => $item['product_id'] ?? null,
                    'category' => $item['category'] ?? null,
                    'item_name' => $item['item_name'],
                    'quantity_scaled' => (int) $item['quantity_scaled'],
                    'unit' => $item['unit'],
                ]);
            }

            return $req;
        });

        $recipients = $this->broadcastService->broadcastRing($marketRequest->fresh(), 1);

        return response()->json([
            'request' => $marketRequest->fresh()->load('items'),
            'counters' => $this->broadcastService->liveCounters($marketRequest->fresh()),
            'ring_1_notified_count' => count($recipients),
        ], 201);
    }

    public function show(Request $request, string $marketplaceRequestId): JsonResponse
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        $marketRequest = MarketplaceRequest::withoutGlobalScopes()
            ->with(['items'])
            ->findOrFail($marketplaceRequestId);

        if ($marketRequest->farmer_id !== $farmerId) {
            abort(403, 'You do not have access to this request.');
        }

        $offers = Offer::withoutGlobalScopes()
            ->where('request_id', '=', $marketRequest->id)
            ->orderByDesc('rank_score_scaled')
            ->get();

        return response()->json([
            'request' => $marketRequest,
            'counters' => $this->broadcastService->liveCounters($marketRequest),
            'offers' => $offers,
        ]);
    }

    public function counters(Request $request, string $marketplaceRequestId): JsonResponse
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        $marketRequest = MarketplaceRequest::withoutGlobalScopes()->findOrFail($marketplaceRequestId);

        if ($marketRequest->farmer_id !== $farmerId) {
            abort(403, 'You do not have access to this request.');
        }

        return response()->json([
            'request_id' => $marketRequest->id,
            'counters' => $this->broadcastService->liveCounters($marketRequest),
        ]);
    }

    public function outletAlerts(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null || $user->tenant_id === null) {
            abort(403, 'Outlet organisation context required.');
        }

        $alerts = RequestRecipient::withoutGlobalScopes()
            ->with(['request.items', 'outlet'])
            ->where('organisation_id', '=', $user->tenant_id)
            ->orderByDesc('notified_at')
            ->limit(50)
            ->get()
            ->map(function (RequestRecipient $r): array {
                $req = $r->request;

                return [
                    'recipient_id' => $r->id,
                    'request_id' => $r->request_id,
                    'outlet_id' => $r->outlet_id,
                    'outlet_name' => $r->outlet?->name,
                    'ring_number' => $r->ring_number,
                    'distance_band' => $r->distance_band,
                    'status' => $req?->status?->value,
                    'needed_by_date' => $req?->needed_by_date?->toDateString(),
                    'expires_at' => $req?->expires_at?->toIso8601String(),
                    'notified_at' => $r->notified_at?->toIso8601String(),
                    'opened_at' => $r->opened_at?->toIso8601String(),
                    'response' => $r->response,
                    'items' => $req ? $req->items->map(fn ($i) => [
                        'item_name' => $i->item_name,
                        'quantity_scaled' => $i->quantity_scaled,
                        'unit' => $i->unit,
                    ])->values()->all() : [],
                ];
            });

        return response()->json(['alerts' => $alerts]);
    }

    public function acceptOffer(Request $request, string $marketplaceRequestId, string $offerId): JsonResponse
    {
        $farmerId = (string) $request->attributes->get('planner_farmer_id', '');
        $farmer = Farmer::query()->findOrFail($farmerId);

        $marketRequest = MarketplaceRequest::withoutGlobalScopes()->findOrFail($marketplaceRequestId);
        if ($marketRequest->farmer_id !== $farmerId) {
            abort(403, 'You do not have access to this request.');
        }

        $offer = Offer::withoutGlobalScopes()
            ->where('request_id', '=', $marketRequest->id)
            ->findOrFail($offerId);

        $validated = $request->validate([
            'grant_contact_reveal_consent' => ['nullable', 'boolean'],
        ]);

        $order = $this->offerAndOrderService->acceptOffer(
            farmer: $farmer,
            offer: $offer,
            grantContactRevealConsent: (bool) ($validated['grant_contact_reveal_consent'] ?? false),
            channel: 'web'
        );

        return response()->json([
            'order' => $order,
            'request_status' => $marketRequest->fresh()->status->value,
        ]);
    }

    public function markOpened(Request $request, string $recipientId): JsonResponse
    {
        $user = $request->user();
        $recipient = RequestRecipient::withoutGlobalScopes()->findOrFail($recipientId);

        if ($user === null || $user->tenant_id !== $recipient->organisation_id) {
            abort(403, 'Forbidden.');
        }

        return response()->json([
            'counters' => $this->broadcastService->recordOpened($recipient),
        ]);
    }

    public function respond(Request $request, string $recipientId): JsonResponse
    {
        $user = $request->user();
        $recipient = RequestRecipient::withoutGlobalScopes()->findOrFail($recipientId);

        if ($user === null || $user->tenant_id !== $recipient->organisation_id) {
            abort(403, 'Forbidden.');
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'in:offer,declined,out_of_stock'],
            'unit_price_minor' => ['required_if:action,offer', 'integer', 'min:1'],
            'total_price_minor' => ['required_if:action,offer', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'in:USD,ZiG'],
            'quantity_available_scaled' => ['required_if:action,offer', 'integer', 'min:1'],
            'fulfilment_type' => ['nullable', 'string', 'in:pickup,delivery,both'],
            'delivery_fee_minor' => ['nullable', 'integer', 'min:0'],
            'valid_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['action'] === 'offer') {
            $offer = $this->offerAndOrderService->submitOffer($recipient, [
                'unit_price_minor' => (int) $validated['unit_price_minor'],
                'total_price_minor' => (int) $validated['total_price_minor'],
                'currency' => $validated['currency'] ?? 'USD',
                'quantity_available_scaled' => (int) $validated['quantity_available_scaled'],
                'fulfilment_type' => $validated['fulfilment_type'] ?? 'pickup',
                'delivery_fee_minor' => (int) ($validated['delivery_fee_minor'] ?? 0),
                'valid_hours' => (int) ($validated['valid_hours'] ?? 12),
                'note' => $validated['note'] ?? null,
                'submitted_by_user_id' => $user->id,
            ]);

            return response()->json(['offer' => $offer], 201);
        }

        $updated = $this->broadcastService->recordRecipientResponse($recipient, $validated['action']);

        return response()->json(['recipient' => $updated]);
    }
}
