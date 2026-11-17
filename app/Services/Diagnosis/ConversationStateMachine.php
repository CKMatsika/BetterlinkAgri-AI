<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\Actions\Consent\ProcessOptOutOrOptInAction;
use App\Actions\Consent\RecordFarmerConsentAction;
use App\Actions\Diagnosis\EscalateToAgronomistAction;
use App\DTOs\MatchedProductRecommendationData;
use App\DTOs\RankedOutletResultData;
use App\DTOs\RichMessageCardData;
use App\Enums\ConversationMode;
use App\Enums\ConversationState;
use App\Enums\HandoffType;
use App\Enums\LeadTemperature;
use App\Enums\RichCardType;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Outlet;
use App\Models\Ward;
use App\Services\Ai\AiAgentService;
use App\Services\Localization\ZimbabweAgronomyGlossary;
use App\Services\Outlets\OutletFinderService;
use App\Services\Outlets\ReservationService;
use DomainException;

/**
 * Section 5: Authoritative Conversation and Diagnosis State Machine.
 *
 * Every inbound message from any channel enters at CONSENT_CHECK.
 * Every state transition is guard-checked, persisted to conversation.state,
 * and recorded in audit_logs.
 */
final class ConversationStateMachine
{
    public function __construct(
        private readonly ProcessOptOutOrOptInAction $optOutAction,
        private readonly RecordFarmerConsentAction $consentAction,
        private readonly IntentRouterService $intentRouter,
        private readonly VisionDiagnosisService $visionService,
        private readonly AdaptiveQuestionEngine $questionEngine,
        private readonly AdvisoryGeneratorService $advisoryGenerator,
        private readonly EscalateToAgronomistAction $escalateAction,
        private readonly OutletFinderService $outletFinder,
        private readonly ReservationService $reservationService,
        private readonly AiAgentService $aiAgent,
        private readonly ZimbabweAgronomyGlossary $glossary,
    ) {
    }

    /**
     * Transition a Conversation to a target state, enforcing transition guards and writing an AuditLog entry.
     *
     * @param array<string, mixed> $metadata
     */
    public function transitionTo(
        Conversation $conversation,
        ConversationState $targetState,
        string $reason,
        array $metadata = []
    ): void {
        $fromState = $conversation->state;

        if ($fromState !== $targetState && !$fromState->canTransitionTo($targetState)) {
            throw new DomainException(
                "Illegal state machine transition from {$fromState->value} to {$targetState->value} (reason: {$reason})."
            );
        }

        $conversation->update([
            'state' => $targetState,
            'state_updated_at' => now(),
        ]);

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => isset($metadata['diagnosis_id']) ? (string) $metadata['diagnosis_id'] : null,
            'event_type' => 'state_transition',
            'from_state' => $fromState->value,
            'to_state' => $targetState->value,
            'metadata_json' => array_merge(['reason' => $reason], $metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * Process an inbound Message through the authoritative state machine and return the outbound Message(s) created.
     *
     * @return array{
     *   state: ConversationState,
     *   reply_text: string,
     *   buttons: list<array{id: string, title: string}>,
     *   rich_cards: list<array<string, mixed>>
     * }
     */
    public function handleInboundTurn(
        Conversation $conversation,
        Message $inboundMessage,
        ?Attachment $attachment = null
    ): array {
        $tenant = $conversation->tenant;
        $farmer = $conversation->farmer;
        $bodyText = trim((string) ($inboundMessage->transcribed_text ?: $inboundMessage->body_text ?: ''));

        // Detect and update language per message if text has clear Shona/Ndebele markers
        if ($bodyText !== '') {
            $detectedLang = $this->glossary->detectLanguage($bodyText, $conversation->language);
            if ($detectedLang !== $conversation->language) {
                $conversation->update(['language' => $detectedLang]);
            }
        }

        // ======================================================================
        // STEP 0: CONSENT_CHECK (Every inbound message enters here)
        // ======================================================================
        if ($this->optOutAction->isOptOutKeyword($bodyText)) {
            $reply = $this->optOutAction->executeOptOut($conversation, $farmer, $bodyText);

            return $this->emitOutbound($conversation, $reply);
        }

        if ($conversation->state === ConversationState::OPTED_OUT) {
            if ($this->optOutAction->isOptInKeyword($bodyText)) {
                $this->optOutAction->executeOptInFromKeyword($conversation, $farmer);
            } else {
                return [
                    'state' => ConversationState::OPTED_OUT,
                    'reply_text' => '',
                    'buttons' => [],
                    'rich_cards' => [],
                ];
            }
        }

        $consent = Consent::query()
            ->where('tenant_id', '=', $tenant->id)
            ->where('farmer_id', '=', $farmer->id)
            ->first();

        if ($conversation->state === ConversationState::AWAITING_CONSENT) {
            $upper = strtoupper($bodyText);
            if (in_array($upper, ['1', 'YES', 'HONGU', 'YEBO', 'YES, I AGREE'], true)) {
                $this->consentAction->execute($conversation, $farmer);
                $this->transitionTo($conversation, ConversationState::INTENT_ROUTING, 'farmer_accepted_consent');

                // Replay buffered message if present
                $buffered = $conversation->pending_message_buffer_json;
                if (is_array($buffered) && !empty($buffered['body_text'])) {
                    $bodyText = (string) $buffered['body_text'];
                    $conversation->update(['pending_message_buffer_json' => null]);
                } else {
                    return $this->emitOutbound(
                        $conversation,
                        'Thank you! Send a photo of any crop pest, leaf disease, nutrient problem, or livestock issue—or type your question.'
                    );
                }
            } elseif (in_array($upper, ['2', 'NO', 'KWETE', 'HATSHI'], true)) {
                $reply = $this->optOutAction->executeOptOut($conversation, $farmer, 'DECLINED_CONSENT');

                return $this->emitOutbound($conversation, $reply);
            } else {
                $prompt = $this->consentAction->buildConsentPromptText($conversation->language, $tenant->name);

                return $this->emitOutbound($conversation, $prompt, [
                    ['id' => '1', 'title' => '1. Yes, I agree'],
                    ['id' => '2', 'title' => '2. No'],
                ]);
            }
        } elseif ($consent === null || !$consent->isOptedIn()) {
            $conversation->update(['pending_message_buffer_json' => ['body_text' => $bodyText]]);
            $this->transitionTo($conversation, ConversationState::AWAITING_CONSENT, 'consent_required');
            $prompt = $this->consentAction->buildConsentPromptText($conversation->language, $tenant->name);

            return $this->emitOutbound($conversation, $prompt, [
                ['id' => '1', 'title' => '1. Yes, I agree'],
                ['id' => '2', 'title' => '2. No'],
            ]);
        }

        if ($conversation->mode === ConversationMode::HUMAN_TAKEOVER) {
            $this->transitionTo($conversation, ConversationState::HUMAN_TAKEOVER, 'human_takeover_mode');

            return [
                'state' => ConversationState::HUMAN_TAKEOVER,
                'reply_text' => '',
                'buttons' => [],
                'rich_cards' => [],
            ];
        }

        // ======================================================================
        // Handle mid-flow states (AWAITING_QUESTION_ANSWER, QUALIFYING_BUYING_INTENT,
        // AWAITING_LOCATION, OUTLET_RESULTS_SENT) before general INTENT_ROUTING
        // ======================================================================
        if ($conversation->state === ConversationState::AWAITING_QUESTION_ANSWER && $attachment === null) {
            return $this->handleClarifyingQuestionAnswer($conversation, $bodyText);
        }

        if ($conversation->state === ConversationState::QUALIFYING_BUYING_INTENT && $attachment === null) {
            return $this->handleQualificationReply($conversation, $bodyText);
        }

        if ($conversation->state === ConversationState::AWAITING_LOCATION && $attachment === null) {
            return $this->handleLocationInputAndFindOutlets($conversation, $bodyText);
        }

        if ($conversation->state === ConversationState::OUTLET_RESULTS_SENT && $attachment === null) {
            return $this->handleOutletSelectionOrReservation($conversation, $bodyText);
        }

        // ======================================================================
        // INTENT_ROUTING
        // ======================================================================
        if ($conversation->state !== ConversationState::INTENT_ROUTING) {
            $this->transitionTo($conversation, ConversationState::INTENT_ROUTING, 'entering_intent_router');
        }

        $routedState = $this->intentRouter->classifyIntent(
            text: $bodyText,
            hasImage: $attachment !== null,
            hasLocation: $inboundMessage->message_type === 'location'
        );

        if ($routedState === ConversationState::HUMAN_TAKEOVER) {
            $this->escalateAction->execute($conversation, null, 'Farmer requested human representative', 'normal', HandoffType::SALES_REP);
            $conversation->update(['mode' => ConversationMode::HUMAN_TAKEOVER]);
            $this->transitionTo($conversation, ConversationState::HUMAN_TAKEOVER, 'farmer_requested_human');

            return $this->emitOutbound(
                $conversation,
                'We have connected your chat to a human representative who will reply here shortly.'
            );
        }

        if ($routedState === ConversationState::HANDOFF_COMMERCIAL_NEGOTIATION) {
            $this->escalateAction->execute($conversation, null, 'Commercial negotiation / credit / discount request', 'high', HandoffType::SALES_REP);
            $conversation->update(['mode' => ConversationMode::HUMAN_TAKEOVER]);
            $this->transitionTo($conversation, ConversationState::HUMAN_TAKEOVER, 'commercial_negotiation_handoff');

            return $this->emitOutbound(
                $conversation,
                'For credit terms, bulk discounts, or contract farming agreements, I am handing your conversation over to our Sales Representative now.'
            );
        }

        if ($routedState === ConversationState::AWAITING_LOCATION) {
            $this->transitionTo($conversation, ConversationState::AWAITING_LOCATION, 'where_to_buy_intent');

            return $this->handleLocationInputAndFindOutlets($conversation, $bodyText);
        }

        if ($routedState === ConversationState::GENERAL_QA) {
            $this->transitionTo($conversation, ConversationState::GENERAL_QA, 'general_qa_intent');
            $ragRes = $this->aiAgent->executeTool($conversation, 'search_knowledge', ['query' => $bodyText]);
            $topChunk = $ragRes['chunks'][0]['content'] ?? 'Send us a photo of your crop or livestock for a full diagnosis.';

            return $this->emitOutbound($conversation, $topChunk);
        }

        // ======================================================================
        // STEP 1 & 2: INTAKE_PROCESSING -> VISION_IDENTIFICATION / TEXT_SYMPTOM_PARSING
        // ======================================================================
        $this->transitionTo($conversation, ConversationState::INTAKE_PROCESSING, 'problem_intake');

        if ($attachment !== null) {
            if (!$attachment->quality_passed) {
                $retries = $conversation->poor_photo_retries + 1;
                $maxRetries = (int) config('betterlink.media.max_poor_photo_retries', 2);

                if ($retries >= $maxRetries) {
                    $this->transitionTo($conversation, ConversationState::TEXT_SYMPTOM_PARSING, 'poor_photo_retries_exceeded');
                    try {
                        $visionDto = $this->visionService->identifyFromTextSymptoms($conversation, $bodyText ?: 'Unclear leaf symptom');

                        return $this->proceedFromIdentification($conversation, $visionDto);
                    } catch (\Throwable $e) {
                        return $this->failClosedToAgronomist($conversation, $e);
                    }
                }

                $conversation->update(['poor_photo_retries' => $retries]);
                $this->transitionTo($conversation, ConversationState::AWAITING_BETTER_IMAGE, 'image_quality_failed');
                $guidance = app(MediaIntakeService::class)->buildBetterPhotoGuidance(
                    $conversation->language,
                    $attachment->quality_failure_reason
                );

                return $this->emitOutbound($conversation, $guidance);
            }

            $this->transitionTo($conversation, ConversationState::VISION_IDENTIFICATION, 'valid_photo_intake');
            try {
                $visionDto = $this->visionService->identifyFromAttachment($conversation, $attachment, $bodyText);
            } catch (\Throwable $e) {
                return $this->failClosedToAgronomist($conversation, $e);
            }

            if ($visionDto->imageQuality === 'poor') {
                $this->transitionTo($conversation, ConversationState::AWAITING_BETTER_IMAGE, 'vision_reported_poor_quality');
                $guidance = app(MediaIntakeService::class)->buildBetterPhotoGuidance($conversation->language, 'too_blurry');

                return $this->emitOutbound($conversation, $guidance);
            }

            return $this->proceedFromIdentification($conversation, $visionDto);
        }

        // Greeting only (no symptom or photo)
        if ($bodyText === '' || preg_match('/^(hi|hello|hey|mhoro|mhoroi|makadii|maswera|sawubona|salibonani)$/i', $bodyText) === 1) {
            $this->transitionTo($conversation, ConversationState::AWAITING_PHOTO_OR_SYMPTOMS, 'greeting_only');

            return $this->emitOutbound(
                $conversation,
                "Hello! Send a clear photo of the affected leaf, insect, stem, fruit, or animal—or describe the symptoms you are seeing in your field."
            );
        }

        // Text or voice symptom only -> parse symptoms & ask for photo alongside first clarifying question
        $this->transitionTo($conversation, ConversationState::TEXT_SYMPTOM_PARSING, 'text_symptom_intake');
        try {
            $visionDto = $this->visionService->identifyFromTextSymptoms($conversation, $bodyText);

            return $this->proceedFromIdentification($conversation, $visionDto, true);
        } catch (\Throwable $e) {
            return $this->failClosedToAgronomist($conversation, $e);
        }
    }

    /**
     * Fail closed when AI analysis is unavailable: log error, enqueue agronomist review, and send no advice.
     *
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function failClosedToAgronomist(Conversation $conversation, \Throwable $e): array
    {
        \Illuminate\Support\Facades\Log::error('AI diagnosis analysis failed; escalating to agronomist queue.', [
            'conversation_id' => $conversation->id,
            'tenant_id' => $conversation->tenant_id,
            'error' => $e->getMessage(),
        ]);

        $this->transitionTo($conversation, ConversationState::HUMAN_TAKEOVER, 'ai_provider_unavailable');
        $this->escalateAction->execute(
            conversation: $conversation,
            diagnosis: null,
            reason: 'AI analysis unavailable: ' . $e->getMessage(),
            priority: 'high',
            handoffType: HandoffType::AGRONOMIST
        );

        return $this->emitOutbound(
            $conversation,
            'I cannot analyse this right now. Please try again shortly, or ask for an agronomist.'
        );
    }

    /**
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function proceedFromIdentification(
        Conversation $conversation,
        \App\DTOs\VisionIdentificationResultData $visionDto,
        bool $promptForPhoto = false
    ): array {
        /** @var Diagnosis $diagnosis */
        $diagnosis = $conversation->diagnoses()->latest()->firstOrFail();

        if ($visionDto->isHighStakesOrNotifiable) {
            $this->transitionTo($conversation, ConversationState::ESCALATED_HIGH_STAKES, 'notifiable_or_high_stakes_detected', [
                'diagnosis_id' => $diagnosis->id,
            ]);
            $this->escalateAction->execute(
                conversation: $conversation,
                diagnosis: $diagnosis,
                reason: "High-stakes / notifiable condition detected: {$diagnosis->final_diagnosis_name}",
                priority: 'urgent_outbreak',
                handoffType: $diagnosis->livestock_id !== null ? HandoffType::VET : HandoffType::AGRONOMIST
            );

            // Still provide grounded emergency guidance + approved catalogue treatment if available
            $advisory = $this->advisoryGenerator->generateGroundedAdvisory($conversation->tenant, $conversation, $diagnosis, false);

            return $this->emitOutbound(
                $conversation,
                "🚨 *URGENT VETERINARY / AGRONOMY ESCALATION:* This condition (*{$diagnosis->final_diagnosis_name}*) is high-stakes and has been escalated to our specialist queue.\n\n"
                . $advisory->renderedWhatsAppText,
                [],
                array_map(fn (RichMessageCardData $c) => $c->toArray(), $advisory->richCards)
            );
        }

        $this->transitionTo($conversation, ConversationState::CLARIFYING_QUESTIONS, 'entering_clarifying_loop', [
            'diagnosis_id' => $diagnosis->id,
        ]);

        return $this->runAdaptiveQuestionStep($conversation, $diagnosis, $visionDto, $promptForPhoto);
    }

    /**
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function runAdaptiveQuestionStep(
        Conversation $conversation,
        Diagnosis $diagnosis,
        ?\App\DTOs\VisionIdentificationResultData $visionDto = null,
        bool $promptForPhoto = false
    ): array {
        $stepResult = $this->questionEngine->evaluateNextStep(
            diagnosis: $diagnosis,
            tenant: $conversation->tenant,
            language: $conversation->language
        );

        if ($stepResult['decision'] === 'ask_question' && isset($stepResult['question'])) {
            $q = $stepResult['question'];
            $this->transitionTo($conversation, ConversationState::AWAITING_QUESTION_ANSWER, "asked_question_{$q->questionOrder}", [
                'diagnosis_id' => $diagnosis->id,
                'question_key' => $q->questionKey,
            ]);

            $prefix = '';
            if ($visionDto !== null && $q->questionOrder === 1) {
                $prefix = $this->visionService->formatFirstPassSummary($visionDto, $conversation->tenant->highConfidenceThreshold()) . "\n\n";
                if ($promptForPhoto) {
                    $prefix .= "📸 _(Tip: If you can take a clear close-up photo of the plant/insect, send it anytime for higher accuracy.)_\n\n";
                }
            }

            $buttons = [];
            foreach (array_slice($q->options, 0, 3) as $opt) {
                $buttons[] = [
                    'id' => (string) $opt['id'],
                    'title' => (string) $opt['title'],
                ];
            }

            return $this->emitOutbound(
                $conversation,
                "{$prefix}*Question {$q->questionOrder}:* {$q->questionText}",
                $buttons
            );
        }

        if ($stepResult['decision'] === 'escalate_low_confidence') {
            $this->transitionTo($conversation, ConversationState::ESCALATED_LOW_CONFIDENCE, 'low_confidence_after_5_questions', [
                'diagnosis_id' => $diagnosis->id,
            ]);
            $this->escalateAction->execute(
                conversation: $conversation,
                diagnosis: $diagnosis,
                reason: "Confidence ({$diagnosis->confidence}) remained below minimum threshold after 5 clarifying questions."
            );

            return $this->emitOutbound(
                $conversation,
                "Because the symptoms could match several conditions (confidence below threshold), I have escalated your photo and answers to our human Agronomist team for a verified review. In the meantime, avoid applying unapproved chemicals and keep scouting the field."
            );
        }

        // Proceed to Step 4 & 5: DETERMINISTIC_TOOL_EXECUTION -> GUARDRAIL_VALIDATION -> ADVISORY_DELIVERED
        return $this->executeDeterministicAdvisoryFlow(
            $conversation,
            $diagnosis,
            (bool) ($stepResult['stated_uncertainty'] ?? false)
        );
    }

    /**
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function handleClarifyingQuestionAnswer(Conversation $conversation, string $answerText): array
    {
        /** @var Diagnosis|null $diagnosis */
        $diagnosis = $conversation->diagnoses()->latest()->first();
        if ($diagnosis === null) {
            $this->transitionTo($conversation, ConversationState::INTENT_ROUTING, 'no_active_diagnosis');

            return $this->emitOutbound($conversation, 'Please send a photo or describe your crop problem.');
        }

        $this->transitionTo($conversation, ConversationState::CLARIFYING_QUESTIONS, 'farmer_answered_question', [
            'diagnosis_id' => $diagnosis->id,
        ]);

        $rescore = $this->questionEngine->recordAnswerAndRescore($diagnosis, $answerText);

        if ($rescore['escalate_high_stakes']) {
            $this->transitionTo($conversation, ConversationState::ESCALATED_HIGH_STAKES, 'answer_revealed_high_stakes', [
                'diagnosis_id' => $diagnosis->id,
            ]);
            $this->escalateAction->execute(
                conversation: $conversation,
                diagnosis: $diagnosis,
                reason: 'Farmer answer indicated livestock mortality or widespread acute outbreak.',
                priority: 'urgent_outbreak',
                handoffType: $diagnosis->livestock_id !== null ? HandoffType::VET : HandoffType::AGRONOMIST
            );

            return $this->emitOutbound(
                $conversation,
                '🚨 Because animals are dying or severely affected, we have escalated your case immediately to a Veterinary/Agronomy Specialist.'
            );
        }

        return $this->runAdaptiveQuestionStep($conversation, $diagnosis->fresh() ?? $diagnosis);
    }

    /**
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function executeDeterministicAdvisoryFlow(
        Conversation $conversation,
        Diagnosis $diagnosis,
        bool $statedUncertainty
    ): array {
        $this->transitionTo($conversation, ConversationState::DETERMINISTIC_TOOL_EXECUTION, 'running_deterministic_tools', [
            'diagnosis_id' => $diagnosis->id,
        ]);

        $advisoryDto = $this->advisoryGenerator->generateGroundedAdvisory(
            tenant: $conversation->tenant,
            conversation: $conversation,
            diagnosis: $diagnosis,
            statedUncertainty: $statedUncertainty
        );

        $this->transitionTo($conversation, ConversationState::GUARDRAIL_VALIDATION, 'validating_guardrails', [
            'diagnosis_id' => $diagnosis->id,
        ]);

        if ($advisoryDto->requiresEscalation) {
            $this->transitionTo($conversation, ConversationState::ESCALATED_NO_REGISTERED_PRODUCT, 'no_registered_product_found', [
                'diagnosis_id' => $diagnosis->id,
            ]);
            $this->escalateAction->execute(
                conversation: $conversation,
                diagnosis: $diagnosis,
                reason: $advisoryDto->escalationReason ?? 'No registered product in catalogue for crop and target.'
            );
            $this->scheduleFollowUp($conversation, $diagnosis);
            $this->transitionTo($conversation, ConversationState::FOLLOW_UP_SCHEDULED, 'scheduled_followup_after_escalation');

            return $this->emitOutbound(
                $conversation,
                $advisoryDto->renderedWhatsAppText,
                [],
                array_map(fn (RichMessageCardData $c) => $c->toArray(), $advisoryDto->richCards)
            );
        }

        $this->transitionTo($conversation, ConversationState::ADVISORY_DELIVERED, 'advisory_delivered', [
            'diagnosis_id' => $diagnosis->id,
        ]);

        $this->transitionTo($conversation, ConversationState::QUALIFYING_BUYING_INTENT, 'prompting_buying_intent', [
            'diagnosis_id' => $diagnosis->id,
        ]);

        return $this->emitOutbound(
            $conversation,
            $advisoryDto->renderedWhatsAppText,
            [
                ['id' => '1', 'title' => '1. Nearest Outlets'],
                ['id' => '2', 'title' => '2. Speak to Rep'],
                ['id' => '3', 'title' => '3. Later'],
            ],
            array_map(fn (RichMessageCardData $c) => $c->toArray(), $advisoryDto->richCards)
        );
    }

    /**
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function handleQualificationReply(Conversation $conversation, string $bodyText): array
    {
        $upper = strtoupper(trim($bodyText));

        if (preg_match('/\b(credit|discount|loan|contract)\b/i', $bodyText) === 1) {
            $this->transitionTo($conversation, ConversationState::HANDOFF_COMMERCIAL_NEGOTIATION, 'credit_or_discount_requested');
            $this->escalateAction->execute($conversation, $conversation->diagnoses()->latest()->first(), 'Credit or price negotiation requested', 'high', HandoffType::SALES_REP);
            $conversation->update(['mode' => ConversationMode::HUMAN_TAKEOVER]);
            $this->transitionTo($conversation, ConversationState::HUMAN_TAKEOVER, 'rep_commercial_takeover');

            return $this->emitOutbound($conversation, 'Connecting you to our Sales Representative to discuss credit/contract options.');
        }

        if (in_array($upper, ['1', 'NEAREST OUTLETS', 'BUY', 'STOCK', 'OUTLETS'], true) || str_contains($upper, 'OUTLET')) {
            $this->transitionTo($conversation, ConversationState::AWAITING_LOCATION, 'farmer_requested_outlets');

            // Check if farmer already has a location or ward on file
            $farmer = $conversation->farmer;
            if ($farmer !== null && ($farmer->ward_id !== null || $farmer->locations()->exists())) {
                return $this->handleLocationInputAndFindOutlets($conversation, '');
            }

            return $this->emitOutbound(
                $conversation,
                'Please send your WhatsApp Location Pin (📎 -> Location) or reply with your District / Ward / Business Centre name (e.g. "Glendale", "Bindura", "Marondera") so we can check stock near you.'
            );
        }

        if (in_array($upper, ['2', 'SPEAK TO REP', 'REP'], true)) {
            $this->aiAgent->executeTool($conversation, 'create_lead', [
                'temperature' => LeadTemperature::HOT->value,
                'intent_score' => 85,
                'score_reasons' => ['farmer_requested_rep_after_advisory'],
            ]);
            $this->transitionTo($conversation, ConversationState::LEAD_ROUTED_TO_REP, 'hot_lead_routed_to_rep');
            $this->scheduleFollowUp($conversation, $conversation->diagnoses()->latest()->first());
            $this->transitionTo($conversation, ConversationState::FOLLOW_UP_SCHEDULED, 'followup_scheduled_after_rep_route');

            return $this->emitOutbound(
                $conversation,
                'We have created a priority request for your local Sales Representative to contact you shortly. We will also check in with you in a few days to see how your crop is recovering.'
            );
        }

        // Farmer declines immediate purchase -> log warm/cold lead and schedule follow-up
        $this->aiAgent->executeTool($conversation, 'create_lead', [
            'temperature' => LeadTemperature::COLD->value,
            'intent_score' => 35,
            'score_reasons' => ['advisory_received_deciding_later'],
        ]);
        $this->scheduleFollowUp($conversation, $conversation->diagnoses()->latest()->first());
        $this->transitionTo($conversation, ConversationState::FOLLOW_UP_SCHEDULED, 'farmer_deciding_later');

        return $this->emitOutbound(
            $conversation,
            'No problem! Follow the application and safety steps above. We will check in with you in 7–14 days for a progress photo.'
        );
    }

    /**
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function handleLocationInputAndFindOutlets(Conversation $conversation, string $locationText): array
    {
        $farmer = $conversation->farmer;
        if ($farmer === null) {
            return $this->emitOutbound($conversation, 'Unable to resolve farmer profile.');
        }

        // Try matching a Ward or Growth Point name if the farmer typed text (e.g. "Glendale", "Bindura", "Mazowe")
        if ($locationText !== '') {
            $matchedWard = Ward::query()
                ->where('name', 'LIKE', "%{$locationText}%")
                ->orWhereHas('centroid', fn ($q) => $q->where('growth_point_name', 'LIKE', "%{$locationText}%"))
                ->first();

            if ($matchedWard !== null) {
                $farmer->update(['ward_id' => $matchedWard->id]);
            }
        }

        $diagnosis = $conversation->diagnoses()->with('advisory')->latest()->first();
        $productIds = [];

        if ($diagnosis?->advisory !== null) {
            foreach ((array) ($diagnosis->advisory->products_json ?? []) as $p) {
                if (!empty($p['product_id'])) {
                    $productIds[] = (string) $p['product_id'];
                }
            }
        }

        if ($productIds === []) {
            $productIds = $conversation->tenant->products()->limit(2)->pluck('id')->map(fn ($id) => (string) $id)->all();
        }

        $finderResult = $this->outletFinder->findNearestStockedOutlets(
            tenant: $conversation->tenant,
            farmer: $farmer->fresh() ?? $farmer,
            productIds: $productIds,
            conversationId: $conversation->id
        );

        $this->transitionTo($conversation, ConversationState::OUTLET_RESULTS_SENT, 'outlet_results_dispatched', [
            'radius_used' => $finderResult['radius_used_km'],
            'outlets_count' => count($finderResult['outlets']),
        ]);

        // Store the ranked outlet IDs in conversation metadata so "HOLD 1" maps deterministically
        $meta = $conversation->context_metadata_json ?? [];
        $meta['last_ranked_outlets'] = array_map(fn (RankedOutletResultData $o) => $o->toArray(), $finderResult['outlets']);
        $conversation->update(['context_metadata_json' => $meta]);

        $formattedText = $this->outletFinder->formatOutletResultsForWhatsApp(
            $finderResult['outlets'],
            $finderResult['radius_used_km']
        );

        $cards = [];
        if ($finderResult['outlets'] !== []) {
            $cards[] = (new RichMessageCardData(
                cardType: RichCardType::OUTLET_LIST,
                title: 'Nearest Stocked Outlets',
                contextChip: $conversation->context_chip ?? 'Stock Finder',
                payload: [
                    'radius_used_km' => $finderResult['radius_used_km'],
                    'outlets' => $meta['last_ranked_outlets'],
                ]
            ))->toArray();
        }

        return $this->emitOutbound($conversation, $formattedText, [
            ['id' => 'HOLD_1', 'title' => 'Hold at Outlet #1'],
            ['id' => 'REP', 'title' => 'Speak to Rep'],
        ], $cards);
    }

    /**
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function handleOutletSelectionOrReservation(Conversation $conversation, string $bodyText): array
    {
        $upper = strtoupper(trim($bodyText));
        $rankedOutlets = (array) ($conversation->context_metadata_json['last_ranked_outlets'] ?? []);

        if ($rankedOutlets !== [] && (str_starts_with($upper, 'HOLD') || in_array($upper, ['1', '2', '3'], true))) {
            $index = 0;
            if (preg_match('/(\d+)/', $upper, $m) === 1) {
                $index = max(0, ((int) $m[1]) - 1);
            }

            $chosenData = $rankedOutlets[$index] ?? $rankedOutlets[0];
            $outlet = Outlet::withoutGlobalScopes()->find((string) $chosenData['outlet_id']);

            if ($outlet !== null && $conversation->farmer !== null) {
                $basketItems = [];
                foreach ((array) ($chosenData['basket_stock_items'] ?? []) as $item) {
                    $basketItems[] = [
                        'product_id' => (string) $item['product_id'],
                        'sku' => (string) $item['sku'],
                        'product_name' => (string) $item['product_name'],
                        'pack_size' => (string) $item['pack_size'],
                        'quantity' => 1,
                        'price_usd' => isset($item['price_usd']) ? (float) $item['price_usd'] : null,
                        'price_zig' => isset($item['price_zig']) ? (float) $item['price_zig'] : null,
                    ];
                }

                $diagnosis = $conversation->diagnoses()->latest()->first();
                $reservation = $this->reservationService->createReservationAndLead(
                    tenant: $conversation->tenant,
                    conversation: $conversation,
                    farmer: $conversation->farmer,
                    outlet: $outlet,
                    basketItems: $basketItems,
                    diagnosis: $diagnosis
                );

                $this->transitionTo($conversation, ConversationState::RESERVATION_PENDING, 'farmer_placed_stock_hold', [
                    'reservation_id' => $reservation->id,
                    'outlet_id' => $outlet->id,
                ]);

                $this->scheduleFollowUp($conversation, $diagnosis);

                return $this->emitOutbound(
                    $conversation,
                    "✅ *Stock Hold Requested at {$outlet->name}!*\n"
                    . "Reservation Ref: `" . substr($reservation->id, 0, 8) . "`\n"
                    . "The outlet manager has been notified and will confirm your stock within *30 minutes*."
                );
            }
        }

        if (str_contains($upper, 'REP') || $upper === '2') {
            $this->transitionTo($conversation, ConversationState::LEAD_ROUTED_TO_REP, 'farmer_requested_rep_from_outlets');
            $this->scheduleFollowUp($conversation, $conversation->diagnoses()->latest()->first());
            $this->transitionTo($conversation, ConversationState::FOLLOW_UP_SCHEDULED, 'followup_scheduled');

            return $this->emitOutbound(
                $conversation,
                'A field Sales Representative has been assigned and will contact you shortly.'
            );
        }

        $this->scheduleFollowUp($conversation, $conversation->diagnoses()->latest()->first());
        $this->transitionTo($conversation, ConversationState::FOLLOW_UP_SCHEDULED, 'farmer_will_decide_later');

        return $this->emitOutbound(
            $conversation,
            'Thank you! Feel free to visit any of the outlets above when ready. We will check in with you in a few days.'
        );
    }

    private function scheduleFollowUp(Conversation $conversation, ?Diagnosis $diagnosis): void
    {
        if ($diagnosis === null) {
            return;
        }

        $days = $diagnosis->category->defaultFollowUpDays();

        FollowUp::query()->firstOrCreate(
            [
                'tenant_id' => $conversation->tenant_id,
                'diagnosis_id' => $diagnosis->id,
            ],
            [
                'conversation_id' => $conversation->id,
                'farmer_id' => $conversation->farmer_id,
                'scheduled_for' => now()->addDays($days),
                'outcome' => 'pending',
            ]
        );
    }

    /**
     * @param list<array{id: string, title: string}> $buttons
     * @param list<array<string, mixed>> $richCards
     * @return array{state: ConversationState, reply_text: string, buttons: list<array{id: string, title: string}>, rich_cards: list<array<string, mixed>>}
     */
    private function emitOutbound(
        Conversation $conversation,
        string $replyText,
        array $buttons = [],
        array $richCards = []
    ): array {
        $primaryCardType = isset($richCards[0]['card_type'])
            ? RichCardType::tryFrom((string) $richCards[0]['card_type'])
            : null;

        Message::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'sender_type' => 'ai_agent',
            'message_type' => $primaryCardType !== null ? 'rich_card' : ($buttons !== [] ? 'interactive' : 'text'),
            'card_type' => $primaryCardType,
            'card_payload_json' => $richCards !== [] ? $richCards : null,
            'body_text' => $replyText,
            'metadata_json' => $buttons !== [] ? ['buttons' => $buttons] : null,
        ]);

        return [
            'state' => $conversation->state,
            'reply_text' => $replyText,
            'buttons' => $buttons,
            'rich_cards' => $richCards,
        ];
    }
}
