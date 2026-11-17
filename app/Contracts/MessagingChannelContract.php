<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Conversation;

interface MessagingChannelContract
{
    /**
     * Send a plain text message to the farmer on the conversation's channel.
     */
    public function sendText(Conversation $conversation, string $text): ?string;

    /**
     * Send an interactive message with up to 3 quick-reply buttons.
     *
     * @param list<array{id: string, title: string}> $buttons
     */
    public function sendInteractiveButtons(
        Conversation $conversation,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?string $footerText = null
    ): ?string;

    /**
     * Send an interactive list message (up to 10 rows).
     *
     * @param list<array{id: string, title: string, description?: string}> $rows
     */
    public function sendInteractiveList(
        Conversation $conversation,
        string $bodyText,
        string $buttonLabel,
        array $rows,
        ?string $headerText = null
    ): ?string;
}
