<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Consent\RecordLocationConsentAction;
use App\Contracts\VoiceTranscriberContract;
use App\Enums\ConversationMode;
use App\Enums\ConversationState;
use App\Enums\LanguageCode;
use App\Http\Requests\Api\V1\WebChatMessageRequest;
use App\Http\Resources\Api\V1\ConversationTurnResource;
use App\Models\Advisory;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\FarmerLocation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Diagnosis\ConversationStateMachine;
use App\Services\Diagnosis\MediaIntakeService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebChatController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly MediaIntakeService $mediaIntakeService,
        private readonly VoiceTranscriberContract $voiceTranscriber,
        private readonly RecordLocationConsentAction $locationConsentAction,
        private readonly ConversationStateMachine $stateMachine
    ) {
    }

    /**
     * Handle an inbound turn from the Web Chat Widget or Flutter Mobile App.
     */
    public function sendMessage(WebChatMessageRequest $request): ConversationTurnResource
    {
        $validated = $request->validated();
        $tenant = $this->resolveActiveTenant($request);

        return $this->tenantContext->runForTenant($tenant, function () use ($validated, $request, $tenant): ConversationTurnResource {
            $farmer = Farmer::findOrCreateByPhone(
                (string) $validated['phone'],
                isset($validated['farmer_name']) ? (string) $validated['farmer_name'] : null
            );

            if (!empty($validated['language'])) {
                $lang = LanguageCode::tryFrom((string) $validated['language']) ?? LanguageCode::ENGLISH;
                $farmer->update(['preferred_language' => $lang]);
            }

            $conversation = Conversation::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'farmer_id' => $farmer->id,
                    'channel' => 'web_chat',
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

            $conversation->update(['last_message_at' => now()]);

            $hasPhoto = $request->hasFile('photo') || !empty($validated['photo_base64']);
            $hasVoice = $request->hasFile('voice_note');
            $hasLocation = isset($validated['latitude'], $validated['longitude']);

            $msgType = $hasPhoto ? 'image' : ($hasVoice ? 'audio' : ($hasLocation ? 'location' : 'text'));

            $message = Message::query()->create([
                'tenant_id' => $tenant->id,
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'sender_type' => 'farmer',
                'message_type' => $msgType,
                'body_text' => isset($validated['message_text']) ? (string) $validated['message_text'] : null,
            ]);

            if ($hasLocation) {
                $lat = (float) $validated['latitude'];
                $lng = (float) $validated['longitude'];
                $this->locationConsentAction->execute($tenant->id, $farmer, true, false, $conversation->id);
                FarmerLocation::query()->create([
                    'farmer_id' => $farmer->id,
                    'tenant_id' => $tenant->id,
                    'ward_id' => $farmer->ward_id,
                    'latitude_encrypted' => (string) $lat,
                    'longitude_encrypted' => (string) $lng,
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'source' => 'app_gps',
                    'is_approximate' => false,
                    'captured_at' => now(),
                ]);
            }

            $attachment = null;
            if ($request->hasFile('photo')) {
                $bytes = (string) file_get_contents($request->file('photo')->getRealPath());
                $this->mediaIntakeService->processAndStoreImage($bytes, $message, $farmer);
                $attachment = $message->attachments()->latest()->first();
            } elseif (!empty($validated['photo_base64'])) {
                $rawB64 = (string) $validated['photo_base64'];
                if (str_contains($rawB64, ',')) {
                    [, $rawB64] = explode(',', $rawB64, 2);
                }
                $bytes = (string) base64_decode($rawB64, true);
                if ($bytes !== '') {
                    $this->mediaIntakeService->processAndStoreImage($bytes, $message, $farmer);
                    $attachment = $message->attachments()->latest()->first();
                }
            } elseif ($hasVoice && $request->file('voice_note') !== null) {
                $audioBytes = (string) file_get_contents($request->file('voice_note')->getRealPath());
                $tr = $this->voiceTranscriber->transcribe($audioBytes);
                $message->update([
                    'transcribed_text' => $tr['text'],
                    'detected_language' => $tr['language']->value,
                ]);
                $conversation->update(['language' => $tr['language']]);
            }

            $turnResult = $this->stateMachine->handleInboundTurn(
                conversation: $conversation,
                inboundMessage: $message->fresh() ?? $message,
                attachment: $attachment
            );

            $freshConv = $conversation->fresh() ?? $conversation;

            return new ConversationTurnResource([
                'conversation_id' => $freshConv->id,
                'state' => $freshConv->state->value,
                'mode' => $freshConv->mode->value,
                'language' => $freshConv->language->value,
                'context_chip' => $freshConv->context_chip ?? 'General Agronomy',
                'reply_text' => $turnResult['reply_text'],
                'buttons' => $turnResult['buttons'],
                'rich_cards' => $turnResult['rich_cards'],
            ]);
        });
    }

    /**
     * Record farmer satisfaction feedback (thumbs up/down and 1-10 rating) on the latest Advisory.
     */
    public function submitFeedback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'uuid'],
            'thumbs_up' => ['nullable', 'boolean'],
            'rating' => ['nullable', 'integer', 'between:1,10'],
            'feedback_text' => ['nullable', 'string', 'max:500'],
        ]);

        $conversation = Conversation::withoutGlobalScopes()->findOrFail((string) $validated['conversation_id']);
        $diagnosis = $conversation->diagnoses()->latest()->first();

        if ($diagnosis === null) {
            return response()->json(['status' => 'no_diagnosis_found'], Response::HTTP_NOT_FOUND);
        }

        $advisory = Advisory::withoutGlobalScopes()->where('diagnosis_id', '=', $diagnosis->id)->first();
        if ($advisory !== null) {
            $advisory->update([
                'farmer_thumbs_up' => $validated['thumbs_up'] ?? $advisory->farmer_thumbs_up,
                'farmer_rating' => $validated['rating'] ?? $advisory->farmer_rating,
                'farmer_feedback_text' => $validated['feedback_text'] ?? $advisory->farmer_feedback_text,
            ]);
        }

        return response()->json([
            'status' => 'feedback_recorded',
            'advisory_id' => $advisory?->id,
        ], Response::HTTP_OK);
    }

    private function resolveActiveTenant(Request $request): Tenant
    {
        $slug = (string) ($request->header('X-Tenant-Slug') ?? $request->input('tenant_slug', 'farmserve-zimbabwe'));

        $tenant = Tenant::query()
            ->where('slug', '=', $slug)
            ->where('is_active', '=', true)
            ->first()
            ?? Tenant::query()->where('is_active', '=', true)->first();

        if ($tenant === null) {
            abort(Response::HTTP_SERVICE_UNAVAILABLE, 'No active tenant configured.');
        }

        return $tenant;
    }
}
