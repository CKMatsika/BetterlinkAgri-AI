<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\ConversationMode;
use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Diagnosis\ConversationStateMachine;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * SMS & USSD Fallback Gateway Controller (Econet / NetOne / Africa's Talking).
 */
final class SmsUssdWebhookController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ConversationStateMachine $stateMachine
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'min:9', 'max:25'],
            'text' => ['required', 'string', 'max:500'],
            'channel' => ['nullable', 'string', 'in:sms,ussd'],
            'tenant_slug' => ['nullable', 'string'],
        ]);

        $tenant = Tenant::query()
            ->where('slug', '=', (string) ($validated['tenant_slug'] ?? 'farmserve-zimbabwe'))
            ->first()
            ?? Tenant::query()->where('is_active', '=', true)->firstOrFail();

        return $this->tenantContext->runForTenant($tenant, function () use ($validated, $tenant): JsonResponse {
            $channel = (string) ($validated['channel'] ?? 'sms');
            $farmer = Farmer::findOrCreateByPhone((string) $validated['from']);

            $conversation = Conversation::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'farmer_id' => $farmer->id,
                    'channel' => $channel,
                ],
                [
                    'external_thread_id' => $farmer->phone_encrypted,
                    'state' => ConversationState::CONSENT_CHECK,
                    'mode' => ConversationMode::AI_ACTIVE,
                    'language' => $farmer->preferred_language,
                    'state_updated_at' => now(),
                    'last_message_at' => now(),
                ]
            );

            $message = Message::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'sender_type' => 'farmer',
                'message_type' => 'text',
                'body_text' => (string) $validated['text'],
            ]);

            $turnResult = $this->stateMachine->handleInboundTurn($conversation, $message);

            // Strip markdown formatting for 160/320-char SMS/USSD plain-text compatibility
            $plainReply = str_replace(['*', '_'], '', $turnResult['reply_text']);

            return response()->json([
                'channel' => $channel,
                'state' => $turnResult['state']->value,
                'ussd_session' => $channel === 'ussd' ? 'CON ' . Str::limit($plainReply, 160, '...') : null,
                'sms_reply' => Str::limit($plainReply, 480, '...'),
            ], Response::HTTP_OK);
        });
    }
}
