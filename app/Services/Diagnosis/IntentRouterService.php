<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\Enums\ConversationState;

/**
 * Classifies inbound messages at INTENT_ROUTING.
 * Invariant: Problem diagnosis and buy/stock intents ALWAYS take priority over profiling questions.
 */
final class IntentRouterService
{
    public function classifyIntent(?string $text, bool $hasImage = false, bool $hasLocation = false): ConversationState
    {
        if ($hasImage) {
            return ConversationState::INTAKE_PROCESSING;
        }

        if ($hasLocation) {
            return ConversationState::AWAITING_LOCATION;
        }

        $lower = strtolower(trim((string) $text));
        if ($lower === '') {
            return ConversationState::INTAKE_PROCESSING;
        }

        // 1. Check for explicit human representative / agronomist request
        if (preg_match('/\b(speak to a person|talk to a rep|human|agent|mudhumeni chaiye|umlimisi)\b/i', $lower) === 1) {
            return ConversationState::HUMAN_TAKEOVER;
        }

        // 2. Check for commercial negotiation (credit, discount, contract terms) -> Handoff
        if (preg_match('/\b(discount|credit terms|loan|contract farming terms|negotiate price|chikwereti)\b/i', $lower) === 1) {
            return ConversationState::HANDOFF_COMMERCIAL_NEGOTIATION;
        }

        // 3. Check for Buy / Where to Buy / Stock / Price request (High Priority)
        if (preg_match('/\b(where to buy|nearest outlet|in stock|stockist|branch|shop|hold this|price of|kutenga kupi|isitolo)\b/i', $lower) === 1) {
            return ConversationState::AWAITING_LOCATION;
        }

        // 4. Check for Crop or Animal Problem / Symptom description (High Priority)
        if (preg_match('/\b(worm|caterpillar|borer|aphid|mite|blight|spot|rust|wilt|streak|yellow|purple|stunted|dying|sick|ticks|gonye|honye|chiburi|mashizha|amacimbi|impethu|umkhuhlane)\b/i', $lower) === 1) {
            return ConversationState::INTAKE_PROCESSING;
        }

        // 5. Check for Event RSVP or Programme reply
        if (preg_match('/\b(rsvp|going to field day|enrol|join programme)\b/i', $lower) === 1) {
            return ConversationState::PROGRAMME_FLOW;
        }

        // 6. Check if pure greeting
        if (preg_match('/^(hi|hello|hey|mhoro|mhoroi|makadii|maswera|sawubona|salibonani|good morning|good afternoon)$/i', $lower) === 1) {
            return ConversationState::INTAKE_PROCESSING;
        }

        return ConversationState::GENERAL_QA;
    }
}
