<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

final class ScheduleMeetingTool implements AiToolContract
{
    public function name(): string
    {
        return 'schedule_meeting';
    }

    public function description(): string
    {
        return 'Propose or confirm 2-3 rep availability slots for a phone call or farm visit and generate an ICS calendar file.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'meeting_type' => ['type' => 'string', 'enum' => ['phone_call', 'whatsapp_call', 'farm_visit']],
                'confirmed_slot_iso' => ['type' => 'string', 'description' => 'Optional confirmed ISO8601 start time'],
            ],
            'required' => ['meeting_type'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $rep = User::query()
            ->where('tenant_id', '=', $conversation->tenant_id)
            ->where('role', '=', UserRole::SALES_REP)
            ->first()
            ?? User::query()->where('tenant_id', '=', $conversation->tenant_id)->first();

        if ($rep === null) {
            return ['error' => 'No representative available for scheduling'];
        }

        $slot1 = now('Africa/Harare')->addDay()->setTime(9, 0);
        $slot2 = now('Africa/Harare')->addDay()->setTime(14, 30);
        $slot3 = now('Africa/Harare')->addDays(2)->setTime(10, 0);

        $proposedSlots = [
            $slot1->toIso8601String(),
            $slot2->toIso8601String(),
            $slot3->toIso8601String(),
        ];

        $confirmedStart = !empty($input['confirmed_slot_iso']) ? now()->parse((string) $input['confirmed_slot_iso']) : $slot1;
        $confirmedEnd = $confirmedStart->copy()->addMinutes(45);

        $icsContent = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//BetterlinkAgr AI//ZW\r\nBEGIN:VEVENT\r\n"
            . 'DTSTART:' . $confirmedStart->utc()->format('Ymd\THis\Z') . "\r\n"
            . 'DTEND:' . $confirmedEnd->utc()->format('Ymd\THis\Z') . "\r\n"
            . "SUMMARY:BetterlinkAgr AI Farmer Consultation ({$input['meeting_type']})\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $icsPath = sprintf('tenants/%s/meetings/meeting_%s.ics', $conversation->tenant_id, uniqid());
        Storage::disk((string) config('betterlink.media.disk', 'local'))->put($icsPath, $icsContent);

        $lead = $conversation->tenant->conversations()->find($conversation->id)?->diagnoses()->latest()->first();

        $meeting = Meeting::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'farmer_id' => $conversation->farmer_id,
            'rep_user_id' => $rep->id,
            'meeting_type' => (string) $input['meeting_type'],
            'proposed_slots_json' => $proposedSlots,
            'confirmed_starts_at' => $confirmedStart,
            'confirmed_ends_at' => $confirmedEnd,
            'ics_file_path' => $icsPath,
            'status' => 'confirmed',
        ]);

        return [
            'meeting_id' => $meeting->id,
            'rep_name' => $rep->name,
            'proposed_slots' => $proposedSlots,
            'confirmed_starts_at' => $confirmedStart->toIso8601String(),
            'ics_file_path' => $icsPath,
        ];
    }
}
