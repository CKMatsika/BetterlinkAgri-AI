<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConversationState;
use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\AuditLog;
use App\Models\Handoff;
use App\Models\Reservation;
use App\Services\Leads\HandoffRoutingService;
use Illuminate\Console\Command;

final class CheckReservationAndLeadSlasCommand extends Command
{
    protected $signature = 'betterlink:check-slas';

    protected $description = 'Check expired 30-min outlet reservation SLAs and 2-hour rep handoff SLAs, cascading to next nearest outlet or sales rep.';

    public function handle(HandoffRoutingService $handoffRouting): int
    {
        // 1. Check expired pending Outlet Reservations (30-min SLA)
        $expiredReservations = Reservation::withoutGlobalScopes()
            ->with(['conversation', 'outlet', 'lead', 'diagnosis'])
            ->where('status', '=', 'pending')
            ->where('sla_due_at', '<', now())
            ->get();

        foreach ($expiredReservations as $reservation) {
            $reservation->update(['status' => 'expired']);
            $conversation = $reservation->conversation;

            if ($conversation === null) {
                continue;
            }

            $rankedOutlets = (array) ($conversation->context_metadata_json['last_ranked_outlets'] ?? []);
            $triedOutletIds = Reservation::withoutGlobalScopes()
                ->where('conversation_id', '=', $conversation->id)
                ->pluck('outlet_id')
                ->map(fn ($id) => (string) $id)
                ->all();

            $nextOutlet = null;
            foreach ($rankedOutlets as $idx => $candidate) {
                if (!in_array((string) ($candidate['outlet_id'] ?? ''), $triedOutletIds, true)) {
                    $nextOutlet = ['index' => $idx + 1, 'data' => $candidate];
                    break;
                }
            }

            if ($nextOutlet !== null) {
                // Offer next nearest outlet -> OUTLET_RESULTS_SENT
                $prevState = $conversation->state;
                $conversation->update([
                    'state' => ConversationState::OUTLET_RESULTS_SENT,
                    'state_updated_at' => now(),
                ]);

                AuditLog::withoutGlobalScopes()->create([
                    'tenant_id' => $reservation->tenant_id,
                    'conversation_id' => $conversation->id,
                    'event_type' => 'state_transition',
                    'from_state' => $prevState->value,
                    'to_state' => ConversationState::OUTLET_RESULTS_SENT->value,
                    'metadata_json' => [
                        'reason' => 'outlet_sla_expired_offering_next_nearest',
                        'expired_reservation_id' => $reservation->id,
                        'next_outlet_id' => $nextOutlet['data']['outlet_id'],
                    ],
                    'created_at' => now(),
                ]);

                SendOutboundChannelMessageJob::dispatch(
                    $conversation->id,
                    "⏰ *{$reservation->outlet?->name}* did not confirm within the 30-minute SLA window. Would you like us to hold your basket at the next nearest stocked outlet (*{$nextOutlet['data']['outlet_name']}* — {$nextOutlet['data']['distance_km']} km)? Reply *HOLD {$nextOutlet['index']}* or *REP*."
                );
            } else {
                // All outlets failed -> escalate to Sales Rep (LEAD_ROUTED_TO_REP)
                $prevState = $conversation->state;
                $conversation->update([
                    'state' => ConversationState::LEAD_ROUTED_TO_REP,
                    'state_updated_at' => now(),
                ]);

                if ($reservation->lead !== null) {
                    $handoffRouting->routeLeadToNearestRep(
                        conversation: $conversation,
                        lead: $reservation->lead,
                        diagnosis: $reservation->diagnosis,
                        reason: 'All nearby outlet reservation SLAs expired'
                    );
                }

                AuditLog::withoutGlobalScopes()->create([
                    'tenant_id' => $reservation->tenant_id,
                    'conversation_id' => $conversation->id,
                    'event_type' => 'state_transition',
                    'from_state' => $prevState->value,
                    'to_state' => ConversationState::LEAD_ROUTED_TO_REP->value,
                    'metadata_json' => ['reason' => 'all_outlets_failed_escalated_to_rep'],
                    'created_at' => now(),
                ]);

                SendOutboundChannelMessageJob::dispatch(
                    $conversation->id,
                    'We have escalated your order directly to our regional Sales Representative to arrange stock delivery or pickup for you.'
                );
            }
        }

        // 2. Check expired pending Rep / Agronomist Handoffs
        $breachedHandoffs = Handoff::withoutGlobalScopes()
            ->where('status', '=', 'pending')
            ->where('sla_due_at', '<', now())
            ->get();

        foreach ($breachedHandoffs as $handoff) {
            $handoff->update(['status' => 'sla_breached']);

            AuditLog::withoutGlobalScopes()->create([
                'tenant_id' => $handoff->tenant_id,
                'conversation_id' => $handoff->conversation_id,
                'diagnosis_id' => $handoff->diagnosis_id,
                'event_type' => 'handoff_sla_breached',
                'metadata_json' => ['handoff_id' => $handoff->id],
                'created_at' => now(),
            ]);
        }

        $this->info(sprintf(
            'Processed %d expired reservations and %d breached handoffs.',
            $expiredReservations->count(),
            $breachedHandoffs->count()
        ));

        return self::SUCCESS;
    }
}
