<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Enums\PlanState;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\FarmPlan;
use DomainException;

/**
 * Authoritative Farm Plan Lifecycle State Machine.
 * State is persisted on FarmPlan (resumable over days, multiple plans per farmer).
 * Conversation holds only a pointer (active_farm_plan_id) so mid-interview interruptions
 * (e.g., pest photo diagnosis) do not lose plan progress.
 */
final class PlanStateMachine
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function transitionTo(
        FarmPlan $plan,
        PlanState $targetState,
        string $reason,
        array $metadata = []
    ): void {
        $fromState = $plan->state;

        if ($fromState !== $targetState && !$fromState->canTransitionTo($targetState)) {
            throw new DomainException(
                "Illegal farm plan transition from {$fromState->value} to {$targetState->value} (reason: {$reason})."
            );
        }

        $plan->update([
            'state' => $targetState,
            'state_updated_at' => now(),
            'accepted_at' => $targetState === PlanState::ACCEPTED ? now() : $plan->accepted_at,
        ]);

        AuditLog::query()->create([
            'tenant_id' => $plan->tenant_id,
            'event_type' => 'plan_state_transition',
            'from_state' => $fromState->value,
            'to_state' => $targetState->value,
            'metadata_json' => array_merge([
                'farm_plan_id' => $plan->id,
                'version' => $plan->version,
                'reason' => $reason,
            ], $metadata),
            'created_at' => now(),
        ]);
    }

    /**
     * Attach a FarmPlan pointer to a Conversation without altering diagnosis state.
     */
    public function attachToConversation(Conversation $conversation, FarmPlan $plan): void
    {
        $conversation->update(['active_farm_plan_id' => $plan->id]);
    }

    /**
     * Create a new version of an accepted/in-progress plan when assumptions or start date change.
     */
    public function createNewPlanVersion(FarmPlan $existingPlan, string $reason): FarmPlan
    {
        $newPlan = $existingPlan->replicate(['id', 'created_at', 'updated_at', 'accepted_at']);
        $newPlan->parent_plan_id = $existingPlan->id;
        $newPlan->version = $existingPlan->version + 1;
        $newPlan->state = PlanState::INTERVIEW;
        $newPlan->state_updated_at = now();
        $newPlan->save();

        foreach ($existingPlan->interviewAnswers as $ans) {
            $newPlan->interviewAnswers()->create([
                'question_key' => $ans->question_key,
                'answer_text' => $ans->answer_text,
                'parsed_value_json' => $ans->parsed_value_json,
                'is_flagged_assumption' => $ans->is_flagged_assumption,
                'flag_reason' => $ans->flag_reason,
                'answered_at' => $ans->answered_at,
            ]);
        }

        AuditLog::query()->create([
            'tenant_id' => $newPlan->tenant_id,
            'event_type' => 'plan_version_created',
            'from_state' => $existingPlan->state->value,
            'to_state' => PlanState::INTERVIEW->value,
            'metadata_json' => [
                'parent_plan_id' => $existingPlan->id,
                'new_plan_id' => $newPlan->id,
                'new_version' => $newPlan->version,
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        return $newPlan;
    }
}
