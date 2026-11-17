<?php

declare(strict_types=1);

namespace App\Actions\Outlets;

use App\Enums\ConversationState;
use App\Enums\CurrencyCode;
use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\AuditLog;
use App\Models\Reservation;
use App\Models\SalesAttribution;
use Illuminate\Support\Facades\DB;

/**
 * Handles Outlet Manager actions on a Reservation:
 * - confirm: Confirms stock hold, sets pickup reminder, notifies farmer, transitions conversation to FOLLOW_UP_SCHEDULED.
 * - reject: Marks rejected with reason, cascades to next nearest outlet or sales rep.
 * - alternative: Proposes alternative pack/brand to farmer.
 * - mark_sold: Marks reservation as picked_up/sold, closes Lead as won, and records SalesAttribution
 *   (conversation -> diagnosis -> outlet -> product -> farmer), including first_ai_engaged_sale & upsell flags.
 */
final class RespondToReservationAction
{
    public function confirmHold(Reservation $reservation): Reservation
    {
        $reservation->update([
            'status' => 'confirmed',
            'confirmed_at' => now(),
            'pickup_reminder_at' => now()->addHours(24),
        ]);

        if ($reservation->lead !== null) {
            $reservation->lead->update([
                'status' => 'accepted',
                'first_response_at' => $reservation->lead->first_response_at ?? now(),
            ]);
        }

        if ($reservation->conversation !== null) {
            $reservation->conversation->update([
                'state' => ConversationState::FOLLOW_UP_SCHEDULED,
                'state_updated_at' => now(),
            ]);

            SendOutboundChannelMessageJob::dispatch(
                $reservation->conversation_id,
                "✅ Good news! *{$reservation->outlet?->name}* has confirmed your stock hold (Ref: `"
                . substr($reservation->id, 0, 8) . "`). Your items are reserved at the counter for pickup."
            );
        }

        AuditLog::query()->create([
            'tenant_id' => $reservation->tenant_id,
            'conversation_id' => $reservation->conversation_id,
            'diagnosis_id' => $reservation->diagnosis_id,
            'event_type' => 'reservation_event',
            'metadata_json' => [
                'action' => 'confirmed',
                'reservation_id' => $reservation->id,
                'outlet_id' => $reservation->outlet_id,
            ],
            'created_at' => now(),
        ]);

        return $reservation;
    }

    public function rejectHold(Reservation $reservation, string $reason): Reservation
    {
        $reservation->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
        ]);

        if ($reservation->conversation !== null) {
            $reservation->conversation->update([
                'state' => ConversationState::OUTLET_RESULTS_SENT,
                'state_updated_at' => now(),
            ]);

            SendOutboundChannelMessageJob::dispatch(
                $reservation->conversation_id,
                "⚠️ *{$reservation->outlet?->name}* could not hold the full basket ({$reason}). Reply *HOLD 2* to reserve at the next nearest outlet, or *REP* for direct delivery."
            );
        }

        AuditLog::query()->create([
            'tenant_id' => $reservation->tenant_id,
            'conversation_id' => $reservation->conversation_id,
            'diagnosis_id' => $reservation->diagnosis_id,
            'event_type' => 'reservation_event',
            'metadata_json' => [
                'action' => 'rejected',
                'reservation_id' => $reservation->id,
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        return $reservation;
    }

    public function markFulfilledAndRecordAttribution(
        Reservation $reservation,
        CurrencyCode $currency = CurrencyCode::USD
    ): void {
        DB::transaction(function () use ($reservation, $currency): void {
            $reservation->update([
                'status' => 'picked_up',
                'fulfilled_at' => now(),
            ]);

            if ($reservation->lead !== null) {
                $reservation->lead->update([
                    'status' => 'won',
                    'closed_at' => now(),
                ]);
            }

            $hadPriorPurchases = SalesAttribution::withoutGlobalScopes()
                ->where('tenant_id', '=', $reservation->tenant_id)
                ->where('farmer_id', '=', $reservation->farmer_id)
                ->exists();

            $basket = (array) ($reservation->basket_items_json ?? []);
            foreach ($basket as $idx => $item) {
                if (empty($item['product_id'])) {
                    continue;
                }

                $qty = max(1.0, (float) ($item['quantity'] ?? 1));
                $unitPrice = $currency === CurrencyCode::ZIG
                    ? (float) ($item['price_zig'] ?? 0.0)
                    : (float) ($item['price_usd'] ?? 0.0);

                SalesAttribution::withoutGlobalScopes()->create([
                    'tenant_id' => $reservation->tenant_id,
                    'conversation_id' => $reservation->conversation_id,
                    'diagnosis_id' => $reservation->diagnosis_id,
                    'lead_id' => $reservation->lead_id,
                    'reservation_id' => $reservation->id,
                    'outlet_id' => $reservation->outlet_id,
                    'product_id' => (string) $item['product_id'],
                    'farmer_id' => $reservation->farmer_id,
                    'quantity_sold' => $qty,
                    'total_amount' => round($qty * $unitPrice, 2),
                    'currency' => $currency,
                    'is_first_ai_engaged_sale' => !$hadPriorPurchases && $idx === 0,
                    'is_upsell_on_engaged_farmer' => $hadPriorPurchases || $idx > 0,
                    'attributed_at' => now(),
                ]);
            }

            AuditLog::query()->create([
                'tenant_id' => $reservation->tenant_id,
                'conversation_id' => $reservation->conversation_id,
                'diagnosis_id' => $reservation->diagnosis_id,
                'event_type' => 'sales_attributed',
                'metadata_json' => [
                    'reservation_id' => $reservation->id,
                    'outlet_id' => $reservation->outlet_id,
                    'currency' => $currency->value,
                ],
                'created_at' => now(),
            ]);
        });
    }
}
