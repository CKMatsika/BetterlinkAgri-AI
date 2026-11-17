<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ConversationState;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Channels\WhatsAppCloudService;
use App\Services\Diagnosis\MediaIntakeService;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DownloadAndProcessWhatsAppMediaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $messageId,
        public readonly string $farmerId,
        public readonly string $mediaId,
        public readonly string $messageType,
        public readonly string $mimeType = 'image/jpeg',
    ) {
        $this->onQueue('webhooks-high');
    }

    public function handle(
        TenantContext $tenantContext,
        WhatsAppCloudService $whatsAppService,
        MediaIntakeService $mediaIntakeService
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);

        $tenantContext->runForTenant($tenant, function () use (
            $tenant,
            $whatsAppService,
            $mediaIntakeService
        ): void {
            $conversation = Conversation::query()->findOrFail($this->conversationId);
            $message = Message::query()->findOrFail($this->messageId);
            $farmer = Farmer::query()->findOrFail($this->farmerId);

            $rawBytes = $whatsAppService->downloadMedia($tenant, $this->mediaId);

            if ($this->messageType === 'image') {
                $report = $mediaIntakeService->processAndStoreImage(
                    $rawBytes,
                    $message,
                    $farmer,
                    $this->mimeType
                );

                if (!$report->passed) {
                    $retries = $conversation->poor_photo_retries + 1;
                    $maxRetries = (int) config('betterlink.media.max_poor_photo_retries', 2);

                    if ($retries >= $maxRetries) {
                        $this->transition($conversation, ConversationState::TEXT_SYMPTOM_PARSING, 'max_poor_photo_retries_reached', [
                            'blur_score' => $report->blurScore,
                            'brightness_score' => $report->brightnessScore,
                            'failure_reason' => $report->failureReason,
                        ]);
                    } else {
                        $conversation->update(['poor_photo_retries' => $retries]);
                        $this->transition($conversation, ConversationState::AWAITING_BETTER_IMAGE, 'image_quality_failed', [
                            'blur_score' => $report->blurScore,
                            'brightness_score' => $report->brightnessScore,
                            'failure_reason' => $report->failureReason,
                        ]);

                        $guidance = $mediaIntakeService->buildBetterPhotoGuidance(
                            $conversation->language,
                            $report->failureReason
                        );
                        SendOutboundChannelMessageJob::dispatch($conversation->id, $guidance);
                    }

                    return;
                }

                // Reset retry counter and transition to VISION_IDENTIFICATION
                $conversation->update(['poor_photo_retries' => 0]);
                $this->transition($conversation, ConversationState::VISION_IDENTIFICATION, 'image_quality_passed', [
                    'sha256_hash' => $report->sha256Hash,
                    'blur_score' => $report->blurScore,
                    'brightness_score' => $report->brightnessScore,
                ]);
            }
        });
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function transition(
        Conversation $conversation,
        ConversationState $toState,
        string $reason,
        array $metadata = []
    ): void {
        $fromState = $conversation->state;

        $conversation->update([
            'state' => $toState,
            'state_updated_at' => now(),
        ]);

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'event_type' => 'state_transition',
            'from_state' => $fromState->value,
            'to_state' => $toState->value,
            'attachment_sha256' => isset($metadata['sha256_hash']) ? (string) $metadata['sha256_hash'] : null,
            'metadata_json' => array_merge(['reason' => $reason], $metadata),
            'created_at' => now(),
        ]);
    }
}
