<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Contracts\MessagingChannelContract;
use App\Models\Conversation;
use App\Models\Message;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SendOutboundChannelMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param list<array{id: string, title: string}> $buttons
     */
    public function __construct(
        public readonly string $conversationId,
        public readonly string $bodyText,
        public readonly array $buttons = [],
        public readonly string $senderType = 'ai_agent',
    ) {
        $this->onQueue('notifications');
    }

    public function handle(
        TenantContext $tenantContext,
        MessagingChannelContract $messagingChannel
    ): void {
        $conversation = Conversation::withoutGlobalScopes()
            ->with(['tenant', 'farmer'])
            ->findOrFail($this->conversationId);

        $tenantContext->runForTenant($conversation->tenant_id, function () use (
            $conversation,
            $messagingChannel
        ): void {
            if ($this->buttons !== []) {
                $externalId = $messagingChannel->sendInteractiveButtons(
                    $conversation,
                    $this->bodyText,
                    $this->buttons
                );
                $messageType = 'interactive';
            } else {
                $externalId = $messagingChannel->sendText($conversation, $this->bodyText);
                $messageType = 'text';
            }

            Message::query()->create([
                'tenant_id' => $conversation->tenant_id,
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'sender_type' => $this->senderType,
                'external_message_id' => $externalId ?: null,
                'message_type' => $messageType,
                'body_text' => $this->bodyText,
                'metadata_json' => $this->buttons !== [] ? ['buttons' => $this->buttons] : null,
            ]);
        });
    }
}
