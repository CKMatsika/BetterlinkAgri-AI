<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Authoritative 24-State Conversation and Diagnosis State Machine Enum.
 */
enum ConversationState: string
{
    case CONSENT_CHECK = 'CONSENT_CHECK';
    case OPTED_OUT = 'OPTED_OUT';
    case AWAITING_CONSENT = 'AWAITING_CONSENT';
    case INTENT_ROUTING = 'INTENT_ROUTING';
    case PROFILING_FLOW = 'PROFILING_FLOW';
    case PROGRAMME_FLOW = 'PROGRAMME_FLOW';
    case GENERAL_QA = 'GENERAL_QA';
    case INTAKE_PROCESSING = 'INTAKE_PROCESSING';
    case AWAITING_PHOTO_OR_SYMPTOMS = 'AWAITING_PHOTO_OR_SYMPTOMS';
    case AWAITING_BETTER_IMAGE = 'AWAITING_BETTER_IMAGE';
    case VISION_IDENTIFICATION = 'VISION_IDENTIFICATION';
    case TEXT_SYMPTOM_PARSING = 'TEXT_SYMPTOM_PARSING';
    case CLARIFYING_QUESTIONS = 'CLARIFYING_QUESTIONS';
    case AWAITING_QUESTION_ANSWER = 'AWAITING_QUESTION_ANSWER';
    case DETERMINISTIC_TOOL_EXECUTION = 'DETERMINISTIC_TOOL_EXECUTION';
    case GUARDRAIL_VALIDATION = 'GUARDRAIL_VALIDATION';
    case ADVISORY_DELIVERED = 'ADVISORY_DELIVERED';
    case QUALIFYING_BUYING_INTENT = 'QUALIFYING_BUYING_INTENT';
    case AWAITING_LOCATION = 'AWAITING_LOCATION';
    case OUTLET_RESULTS_SENT = 'OUTLET_RESULTS_SENT';
    case RESERVATION_PENDING = 'RESERVATION_PENDING';
    case LEAD_ROUTED_TO_REP = 'LEAD_ROUTED_TO_REP';
    case MEETING_SCHEDULING = 'MEETING_SCHEDULING';
    case ESCALATED_HIGH_STAKES = 'ESCALATED_HIGH_STAKES';
    case ESCALATED_LOW_CONFIDENCE = 'ESCALATED_LOW_CONFIDENCE';
    case ESCALATED_NO_REGISTERED_PRODUCT = 'ESCALATED_NO_REGISTERED_PRODUCT';
    case HANDOFF_COMMERCIAL_NEGOTIATION = 'HANDOFF_COMMERCIAL_NEGOTIATION';
    case HUMAN_TAKEOVER = 'HUMAN_TAKEOVER';
    case FOLLOW_UP_SCHEDULED = 'FOLLOW_UP_SCHEDULED';
    case AWAITING_FOLLOW_UP_RESPONSE = 'AWAITING_FOLLOW_UP_RESPONSE';
    case FOLLOW_UP_EVALUATION = 'FOLLOW_UP_EVALUATION';
    case RESOLVED_COLLECT_FEEDBACK = 'RESOLVED_COLLECT_FEEDBACK';

    /**
     * Returns allowed target states from the current state per the authoritative state machine diagram.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::CONSENT_CHECK => [
                self::OPTED_OUT,
                self::AWAITING_CONSENT,
                self::HUMAN_TAKEOVER,
                self::INTENT_ROUTING,
            ],
            self::OPTED_OUT => [
                self::CONSENT_CHECK,
            ],
            self::AWAITING_CONSENT => [
                self::INTENT_ROUTING,
                self::OPTED_OUT,
            ],
            self::INTENT_ROUTING => [
                self::INTAKE_PROCESSING,
                self::PROFILING_FLOW,
                self::PROGRAMME_FLOW,
                self::AWAITING_LOCATION,
                self::GENERAL_QA,
                self::HUMAN_TAKEOVER,
                self::OPTED_OUT,
            ],
            self::PROFILING_FLOW, self::PROGRAMME_FLOW => [
                self::INTENT_ROUTING,
                self::OPTED_OUT,
            ],
            self::GENERAL_QA => [
                self::QUALIFYING_BUYING_INTENT,
                self::INTENT_ROUTING,
                self::OPTED_OUT,
            ],
            self::INTAKE_PROCESSING => [
                self::AWAITING_PHOTO_OR_SYMPTOMS,
                self::AWAITING_BETTER_IMAGE,
                self::VISION_IDENTIFICATION,
                self::TEXT_SYMPTOM_PARSING,
                self::OPTED_OUT,
            ],
            self::AWAITING_PHOTO_OR_SYMPTOMS => [
                self::INTAKE_PROCESSING,
                self::INTENT_ROUTING,
                self::OPTED_OUT,
            ],
            self::AWAITING_BETTER_IMAGE => [
                self::VISION_IDENTIFICATION,
                self::TEXT_SYMPTOM_PARSING,
                self::INTAKE_PROCESSING,
                self::OPTED_OUT,
            ],
            self::VISION_IDENTIFICATION => [
                self::AWAITING_BETTER_IMAGE,
                self::ESCALATED_HIGH_STAKES,
                self::CLARIFYING_QUESTIONS,
                self::DETERMINISTIC_TOOL_EXECUTION,
                self::HUMAN_TAKEOVER,
            ],
            self::TEXT_SYMPTOM_PARSING => [
                self::CLARIFYING_QUESTIONS,
                self::ESCALATED_HIGH_STAKES,
                self::HUMAN_TAKEOVER,
            ],
            self::CLARIFYING_QUESTIONS => [
                self::AWAITING_QUESTION_ANSWER,
                self::DETERMINISTIC_TOOL_EXECUTION,
                self::ESCALATED_LOW_CONFIDENCE,
                self::ESCALATED_HIGH_STAKES,
            ],
            self::AWAITING_QUESTION_ANSWER => [
                self::CLARIFYING_QUESTIONS,
                self::INTAKE_PROCESSING,
                self::HUMAN_TAKEOVER,
                self::OPTED_OUT,
            ],
            self::DETERMINISTIC_TOOL_EXECUTION => [
                self::GUARDRAIL_VALIDATION,
            ],
            self::GUARDRAIL_VALIDATION => [
                self::ADVISORY_DELIVERED,
                self::ESCALATED_NO_REGISTERED_PRODUCT,
            ],
            self::ADVISORY_DELIVERED => [
                self::QUALIFYING_BUYING_INTENT,
            ],
            self::QUALIFYING_BUYING_INTENT => [
                self::AWAITING_LOCATION,
                self::LEAD_ROUTED_TO_REP,
                self::HANDOFF_COMMERCIAL_NEGOTIATION,
                self::FOLLOW_UP_SCHEDULED,
                self::INTENT_ROUTING,
                self::OPTED_OUT,
            ],
            self::AWAITING_LOCATION => [
                self::OUTLET_RESULTS_SENT,
                self::LEAD_ROUTED_TO_REP,
                self::FOLLOW_UP_SCHEDULED,
                self::OPTED_OUT,
            ],
            self::OUTLET_RESULTS_SENT => [
                self::RESERVATION_PENDING,
                self::LEAD_ROUTED_TO_REP,
                self::FOLLOW_UP_SCHEDULED,
                self::INTENT_ROUTING,
                self::OPTED_OUT,
            ],
            self::RESERVATION_PENDING => [
                self::FOLLOW_UP_SCHEDULED,
                self::OUTLET_RESULTS_SENT,
                self::LEAD_ROUTED_TO_REP,
            ],
            self::LEAD_ROUTED_TO_REP => [
                self::MEETING_SCHEDULING,
                self::HUMAN_TAKEOVER,
                self::FOLLOW_UP_SCHEDULED,
            ],
            self::MEETING_SCHEDULING => [
                self::FOLLOW_UP_SCHEDULED,
                self::HUMAN_TAKEOVER,
            ],
            self::ESCALATED_HIGH_STAKES,
            self::ESCALATED_LOW_CONFIDENCE,
            self::HANDOFF_COMMERCIAL_NEGOTIATION => [
                self::HUMAN_TAKEOVER,
                self::FOLLOW_UP_SCHEDULED,
            ],
            self::ESCALATED_NO_REGISTERED_PRODUCT => [
                self::FOLLOW_UP_SCHEDULED,
                self::HUMAN_TAKEOVER,
            ],
            self::HUMAN_TAKEOVER => [
                self::FOLLOW_UP_SCHEDULED,
                self::INTENT_ROUTING,
            ],
            self::FOLLOW_UP_SCHEDULED => [
                self::AWAITING_FOLLOW_UP_RESPONSE,
                self::INTAKE_PROCESSING,
                self::INTENT_ROUTING,
                self::OPTED_OUT,
            ],
            self::AWAITING_FOLLOW_UP_RESPONSE => [
                self::FOLLOW_UP_EVALUATION,
                self::INTAKE_PROCESSING,
                self::RESOLVED_COLLECT_FEEDBACK,
                self::OPTED_OUT,
            ],
            self::FOLLOW_UP_EVALUATION => [
                self::RESOLVED_COLLECT_FEEDBACK,
                self::ESCALATED_HIGH_STAKES,
                self::INTAKE_PROCESSING,
            ],
            self::RESOLVED_COLLECT_FEEDBACK => [
                self::INTENT_ROUTING,
                self::CONSENT_CHECK,
                self::OPTED_OUT,
            ],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        if ($target === self::CONSENT_CHECK || $target === self::OPTED_OUT) {
            return true;
        }

        return in_array($target, $this->allowedTransitions(), true);
    }
}
