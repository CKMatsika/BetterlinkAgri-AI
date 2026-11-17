<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Contracts\MessagingChannelContract;
use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local/testing MessagingChannelContract implementation that logs outbound messages
 * instead of calling Meta's WhatsApp Cloud API.
 */
final class LogWhatsAppChannel implements MessagingChannelContract
{
    public function sendText(Conversation $conversation, string $text): ?string
    {
        $id = 'wamid.log.' . Str::uuid();
        Log::info('LogWhatsAppChannel outbound text', [
            'message_id' => $id,
            'conversation_id' => $conversation->id,
            'length' => strlen($text),
        ]);

        return $id;
    }

    public function sendInteractiveButtons(
        Conversation $conversation,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?string $footerText = null
    ): ?string {
        $id = 'wamid.log.' . Str::uuid();
        Log::info('LogWhatsAppChannel outbound buttons', [
            'message_id' => $id,
            'conversation_id' => $conversation->id,
            'button_count' => count($buttons),
        ]);

        return $id;
    }

    public function sendInteractiveList(
        Conversation $conversation,
        string $bodyText,
        string $buttonLabel,
        array $rows,
        ?string $headerText = null
    ): ?string {
        $id = 'wamid.log.' . Str::uuid();
        Log::info('LogWhatsAppChannel outbound list', [
            'message_id' => $id,
            'conversation_id' => $conversation->id,
            'row_count' => count($rows),
        ]);

        return $id;
    }
}
