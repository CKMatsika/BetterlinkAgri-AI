<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Consent\RecordLocationConsentAction;
use App\Contracts\VoiceTranscriberContract;
use App\DTOs\InboundMessageData;
use App\Enums\ConversationMode;
use App\Enums\ConversationState;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\FarmerLocation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Channels\WhatsAppCloudService;
use App\Services\Diagnosis\ConversationStateMachine;
use App\Services\Diagnosis\MediaIntakeService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class ProcessInboundWhatsAppMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param array<string, mixed> $inboundDataArray
     */
    public function __construct(
        public readonly array $inboundDataArray
    ) {
        $this->onQueue('webhooks-high');
    }

    public function handle(
        TenantContext $tenantContext,
        RecordLocationConsentAction $locationConsentAction,
        WhatsAppCloudService $whatsAppService,
        MediaIntakeService $mediaIntakeService,
        VoiceTranscriberContract $voiceTranscriber,
        ConversationStateMachine $stateMachine
    ): void {
        $dto = InboundMessageData::fromArray($this->inboundDataArray);
        $tenant = Tenant::query()->findOrFail($dto->tenantId);

        $tenantContext->runForTenant($tenant, function () use (
            $dto,
            $tenant,
            $locationConsentAction,
            $whatsAppService,
            $mediaIntakeService,
            $voiceTranscriber,
            $stateMachine
        ): void {
            // 1. Resolve global Farmer identity via HMAC-SHA256 blind index
            $farmer = Farmer::findOrCreateByPhone($dto->senderPhone, $dto->senderName);
            $farmer->update(['last_active_at' => now()]);

            // 2. Find or create per-tenant Conversation
            $conversation = Conversation::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'farmer_id' => $farmer->id,
                    'channel' => $dto->channel,
                ],
                [
                    'external_thread_id' => $dto->senderPhone,
                    'state' => ConversationState::CONSENT_CHECK,
                    'mode' => ConversationMode::AI_ACTIVE,
                    'language' => $farmer->preferred_language,
                    'state_updated_at' => now(),
                    'last_message_at' => now(),
                ]
            );

            $conversation->update(['last_message_at' => now()]);

            // 3. Persist Inbound Message (idempotent on external_message_id)
            $message = Message::query()->firstOrCreate(
                ['external_message_id' => $dto->externalMessageId],
                [
                    'tenant_id' => $tenant->id,
                    'conversation_id' => $conversation->id,
                    'direction' => 'inbound',
                    'sender_type' => 'farmer',
                    'message_type' => $dto->messageType,
                    'body_text' => $dto->bodyText,
                    'metadata_json' => [
                        'media_id' => $dto->mediaId,
                        'media_mime_type' => $dto->mediaMimeType,
                        'latitude' => $dto->latitude,
                        'longitude' => $dto->longitude,
                        'interactive_reply_id' => $dto->interactiveReplyId,
                    ],
                ]
            );

            // 4. Handle Location Pin messages
            if ($dto->messageType === 'location' && $dto->latitude !== null && $dto->longitude !== null) {
                $locationConsentAction->execute($tenant->id, $farmer, true, false, $conversation->id);
                $location = FarmerLocation::query()->create([
                    'farmer_id' => $farmer->id,
                    'tenant_id' => $tenant->id,
                    'ward_id' => $farmer->ward_id,
                    'latitude_encrypted' => (string) $dto->latitude,
                    'longitude_encrypted' => (string) $dto->longitude,
                    'latitude' => $dto->latitude,
                    'longitude' => $dto->longitude,
                    'source' => 'whatsapp_pin',
                    'is_approximate' => false,
                    'captured_at' => now(),
                ]);

                if (DB::getDriverName() === 'pgsql') {
                    DB::statement(
                        'UPDATE farmer_locations SET location_point = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                        [$dto->longitude, $dto->latitude, $location->id]
                    );
                }
            }

            // 5. Handle Voice Notes (transcribe and update message language)
            $attachment = null;
            if ($dto->messageType === 'audio' && $dto->mediaId !== null) {
                $audioBytes = $whatsAppService->downloadMedia($tenant, $dto->mediaId);
                $transcription = $voiceTranscriber->transcribe($audioBytes, $dto->mediaMimeType ?? 'audio/ogg');

                $message->update([
                    'transcribed_text' => $transcription['text'],
                    'detected_language' => $transcription['language']->value,
                ]);
                $conversation->update(['language' => $transcription['language']]);
            } elseif ($dto->messageType === 'image' && $dto->mediaId !== null) {
                $imageBytes = $whatsAppService->downloadMedia($tenant, $dto->mediaId);
                $mediaIntakeService->processAndStoreImage(
                    $imageBytes,
                    $message,
                    $farmer,
                    $dto->mediaMimeType ?? 'image/jpeg'
                );
                $attachment = $message->attachments()->latest()->first();
            }

            // 6. Execute authoritative ConversationStateMachine turn
            $turnOutput = $stateMachine->handleInboundTurn(
                conversation: $conversation,
                inboundMessage: $message->fresh() ?? $message,
                attachment: $attachment
            );

            if ($turnOutput['reply_text'] !== '') {
                SendOutboundChannelMessageJob::dispatch(
                    $conversation->id,
                    $turnOutput['reply_text'],
                    $turnOutput['buttons']
                );
            }
        });
    }
}
