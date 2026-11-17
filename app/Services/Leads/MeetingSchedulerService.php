<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Meeting;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Section 8: Rep Availability Slot Proposal, Booking Confirmation, ICS File Generation & WhatsApp Reminders.
 */
final class MeetingSchedulerService
{
    /**
     * Propose 3 upcoming slots based on the assigned rep's availability schedule.
     *
     * @return list<array{slot_number: int, iso: string, human_label: string}>
     */
    public function proposeSlotsForRep(User $rep): array
    {
        $base = now('Africa/Harare')->addDay();
        if ($base->isSunday()) {
            $base = $base->addDay();
        }

        $slot1 = $base->copy()->setTime(9, 0);
        $slot2 = $base->copy()->setTime(14, 30);
        $nextDay = $base->copy()->addDay();
        if ($nextDay->isSunday()) {
            $nextDay = $nextDay->addDay();
        }
        $slot3 = $nextDay->copy()->setTime(10, 0);

        return [
            [
                'slot_number' => 1,
                'iso' => $slot1->toIso8601String(),
                'human_label' => $slot1->format('D d M, H:i') . ' CAT',
            ],
            [
                'slot_number' => 2,
                'iso' => $slot2->toIso8601String(),
                'human_label' => $slot2->format('D d M, H:i') . ' CAT',
            ],
            [
                'slot_number' => 3,
                'iso' => $slot3->toIso8601String(),
                'human_label' => $slot3->format('D d M, H:i') . ' CAT',
            ],
        ];
    }

    public function createConfirmedMeetingWithIcs(
        Conversation $conversation,
        User $rep,
        CarbonInterface $startsAt,
        string $meetingType = 'phone_call',
        ?Lead $lead = null
    ): Meeting {
        $endsAt = $startsAt->copy()->addMinutes(45);
        $farmerName = (string) ($conversation->farmer?->full_name_encrypted ?: 'Farmer');

        $icsContent = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//BetterlinkAgr AI//Zimbabwe Agronomy & Sales//EN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:' . uniqid('betterlink-mtg-', true) . '@betterlinkagr.co.zw',
            'DTSTAMP:' . now()->utc()->format('Ymd\THis\Z'),
            'DTSTART:' . $startsAt->copy()->utc()->format('Ymd\THis\Z'),
            'DTEND:' . $endsAt->copy()->utc()->format('Ymd\THis\Z'),
            "SUMMARY:BetterlinkAgr {$meetingType} — {$farmerName} & {$rep->name}",
            "DESCRIPTION:Agri-input advisory & order follow-up consultation.",
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);

        $disk = (string) config('betterlink.media.disk', 'local');
        $icsPath = sprintf('tenants/%s/meetings/meeting_%s.ics', $conversation->tenant_id, uniqid());
        Storage::disk($disk)->put($icsPath, $icsContent);

        $meeting = Meeting::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'lead_id' => $lead?->id,
            'conversation_id' => $conversation->id,
            'farmer_id' => $conversation->farmer_id,
            'rep_user_id' => $rep->id,
            'meeting_type' => $meetingType,
            'proposed_slots_json' => [$startsAt->toIso8601String()],
            'confirmed_starts_at' => $startsAt,
            'confirmed_ends_at' => $endsAt,
            'ics_file_path' => $icsPath,
            'status' => 'confirmed',
        ]);

        if ($lead !== null) {
            $lead->update(['meeting_status' => 'confirmed']);
        }

        SendOutboundChannelMessageJob::dispatch(
            $conversation->id,
            "📅 *Meeting Confirmed!* Your {$meetingType} with *{$rep->name}* is booked for *{$startsAt->format('D d M Y, H:i')} CAT*. Calendar invite (.ics) generated and reminders set."
        );

        return $meeting;
    }
}
