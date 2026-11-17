<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ConversationState;
use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\FollowUp;
use Illuminate\Console\Command;

final class DispatchScheduledFollowUpsCommand extends Command
{
    protected $signature = 'betterlink:dispatch-followups';

    protected $description = 'Dispatch due +7d/+14d/+3d follow-up check-ins and expire unanswered check-ins after 2 reminders.';

    public function handle(): int
    {
        $maxReminders = (int) config('betterlink.diagnosis.follow_up_max_reminders', 2);

        $dueFollowUps = FollowUp::withoutGlobalScopes()
            ->with(['conversation', 'diagnosis'])
            ->where('outcome', '=', 'pending')
            ->where('scheduled_for', '<=', now())
            ->get();

        $dispatched = 0;

        foreach ($dueFollowUps as $followUp) {
            $conversation = $followUp->conversation;
            if ($conversation === null) {
                continue;
            }

            // Respect opt-out status
            $consent = Consent::withoutGlobalScopes()
                ->where('tenant_id', '=', $followUp->tenant_id)
                ->where('farmer_id', '=', $followUp->farmer_id)
                ->first();

            if ($consent === null || !$consent->isOptedIn()) {
                $followUp->update(['outcome' => 'no_reply']);
                continue;
            }

            if ($followUp->reminders_sent_count >= $maxReminders) {
                $followUp->update(['outcome' => 'no_reply']);
                continue;
            }

            $prevState = $conversation->state;
            $conversation->update([
                'state' => ConversationState::AWAITING_FOLLOW_UP_RESPONSE,
                'state_updated_at' => now(),
            ]);

            $followUp->update([
                'reminders_sent_count' => $followUp->reminders_sent_count + 1,
                'scheduled_for' => now()->addDays(2),
            ]);

            AuditLog::withoutGlobalScopes()->create([
                'tenant_id' => $followUp->tenant_id,
                'conversation_id' => $conversation->id,
                'diagnosis_id' => $followUp->diagnosis_id,
                'event_type' => 'state_transition',
                'from_state' => $prevState->value,
                'to_state' => ConversationState::AWAITING_FOLLOW_UP_RESPONSE->value,
                'metadata_json' => ['reason' => 'scheduled_followup_timer_fired'],
                'created_at' => now(),
            ]);

            $diagName = $followUp->diagnosis?->final_diagnosis_name ?? 'your crop/livestock';
            SendOutboundChannelMessageJob::dispatch(
                $conversation->id,
                "🌱 *Follow-Up Check-In:* How is your field recovering from *{$diagName}*? Please send us a quick progress photo or reply:\n1. Resolved / Improving\n2. Still a problem / Worsened"
            );

            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} follow-up check-ins.");

        return self::SUCCESS;
    }
}
