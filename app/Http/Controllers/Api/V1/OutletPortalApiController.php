<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Outlets\ParseWhatsAppStockUpdateAction;
use App\Actions\Outlets\RespondToReservationAction;
use App\Enums\CurrencyCode;
use App\Models\Outlet;
use App\Models\Reservation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OutletPortalApiController
{
    public function __construct(
        private readonly ParseWhatsAppStockUpdateAction $stockUpdateAction,
        private readonly RespondToReservationAction $reservationAction
    ) {
    }

    public function updateStockViaText(Request $request, string $outletId): JsonResponse
    {
        $validated = $request->validate([
            'stock_line' => ['required', 'string', 'max:255'],
        ]);

        $outlet = Outlet::withoutGlobalScopes()->findOrFail($outletId);
        $result = $this->stockUpdateAction->execute($outlet, (string) $validated['stock_line']);

        return response()->json($result, $result['updated'] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function respondToReservation(Request $request, string $reservationId): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:confirm,reject,mark_sold'],
            'reason' => ['nullable', 'string', 'max:255'],
            'currency' => ['nullable', 'string', 'in:USD,ZIG'],
        ]);

        $reservation = Reservation::withoutGlobalScopes()->findOrFail($reservationId);
        $action = (string) $validated['action'];

        if ($action === 'confirm') {
            $this->reservationAction->confirmHold($reservation);
        } elseif ($action === 'reject') {
            $this->reservationAction->rejectHold($reservation, (string) ($validated['reason'] ?? 'Out of stock'));
        } elseif ($action === 'mark_sold') {
            $curr = CurrencyCode::tryFrom((string) ($validated['currency'] ?? 'USD')) ?? CurrencyCode::USD;
            $this->reservationAction->markFulfilledAndRecordAttribution($reservation, $curr);
        }

        return response()->json([
            'reservation_id' => $reservation->id,
            'status' => $reservation->fresh()?->status,
        ], Response::HTTP_OK);
    }
}
