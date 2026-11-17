<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\DTOs\RichMessageCardData;
use App\Enums\RichCardType;
use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\Conversation;
use App\Models\Event;
use App\Models\EventRsvp;
use App\Models\Farmer;

/**
 * Section 9 & 10: Field Days, Demonstrations & Webinars Invite & RSVP Service.
 */
final class EventRsvpService
{
    public function sendEventInviteCard(Event $event, Conversation $conversation, Farmer $farmer): RichMessageCardData
    {
        EventRsvp::withoutGlobalScopes()->firstOrCreate(
            [
                'event_id' => $event->id,
                'farmer_id' => $farmer->id,
            ],
            [
                'tenant_id' => $event->tenant_id,
                'rsvp_status' => 'invited',
            ]
        );

        $dateStr = $event->starts_at?->format('D d M Y, H:i') ?? 'Upcoming';
        $card = new RichMessageCardData(
            cardType: RichCardType::EVENT_INVITE,
            title: $event->title,
            contextChip: $conversation->context_chip ?? 'Field Day / Event',
            payload: [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'starts_at' => $dateStr,
                'venue_or_link' => $event->venue_or_link,
                'rsvp_options' => ['going', 'maybe', 'declined'],
            ]
        );

        $inviteText = "📣 *Event Invitation: {$event->title}*\n"
            . "🗓️ Date & Time: {$dateStr}\n"
            . "📍 Venue / Link: {$event->venue_or_link}\n\n"
            . 'Will you attend?';

        SendOutboundChannelMessageJob::dispatch($conversation->id, $inviteText, [
            ['id' => "RSVP_GOING_{$event->id}", 'title' => '1. Going'],
            ['id' => "RSVP_MAYBE_{$event->id}", 'title' => '2. Maybe'],
            ['id' => "RSVP_DECLINED_{$event->id}", 'title' => '3. Cannot make it'],
        ]);

        return $card;
    }

    public function recordRsvpResponse(Event $event, Farmer $farmer, string $status): EventRsvp
    {
        $validStatus = in_array($status, ['going', 'maybe', 'declined', 'attended'], true) ? $status : 'going';

        return EventRsvp::withoutGlobalScopes()->updateOrCreate(
            [
                'event_id' => $event->id,
                'farmer_id' => $farmer->id,
            ],
            [
                'tenant_id' => $event->tenant_id,
                'rsvp_status' => $validStatus,
                'responded_at' => now(),
                'checked_in_at' => $validStatus === 'attended' ? now() : null,
            ]
        );
    }
}
