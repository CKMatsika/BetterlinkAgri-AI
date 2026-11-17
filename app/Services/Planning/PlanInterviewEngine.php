<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Enums\LanguageCode;
use App\Enums\PlanState;
use App\Models\EnterpriseQuestionBank;
use App\Models\FarmPlan;
use App\Models\PlanInterviewAnswer;

/**
 * Data-driven, one-question-at-a-time Farm Planning Interview Engine.
 * Questions are stored in `enterprise_question_banks` (editable by agronomists).
 * Flags unrealistic assumptions without blocking the farmer.
 */
final class PlanInterviewEngine
{
    public function __construct(
        private readonly PlanStateMachine $stateMachine
    ) {
    }

    /**
     * Get the next unanswered question for the FarmPlan, or transition to PLAN_DRAFTED when complete.
     *
     * @return array{
     *   completed: bool,
     *   question?: array{key: string, order: int, text: string, options: list<array<string, mixed>>, is_mandatory: bool},
     *   summary?: array<string, mixed>
     * }
     */
    public function getNextQuestionOrComplete(FarmPlan $plan, LanguageCode $language = LanguageCode::ENGLISH): array
    {
        /** @var list<string> $answeredKeys */
        $answeredKeys = $plan->interviewAnswers()
            ->pluck('question_key')
            ->map(fn ($k) => (string) $k)
            ->all();

        $nextQuestion = EnterpriseQuestionBank::query()
            ->where('enterprise_id', '=', $plan->enterprise_id)
            ->where('is_active', '=', true)
            ->whereNotIn('question_key', $answeredKeys)
            ->orderBy('question_order')
            ->first();

        if ($nextQuestion === null) {
            if ($plan->state === PlanState::INTERVIEW) {
                $this->stateMachine->transitionTo($plan, PlanState::PLAN_DRAFTED, 'all_mandatory_interview_questions_answered');
            }

            return [
                'completed' => true,
                'summary' => $this->buildConfirmationSummary($plan),
            ];
        }

        return [
            'completed' => false,
            'question' => [
                'key' => $nextQuestion->question_key,
                'order' => $nextQuestion->question_order,
                'text' => $nextQuestion->localizedQuestionText($language),
                'options' => (array) ($nextQuestion->options_json ?? []),
                'is_mandatory' => $nextQuestion->is_mandatory,
            ],
        ];
    }

    /**
     * Record a farmer's answer, validate against enterprise rules (flagging unrealistic inputs without blocking),
     * and return whether a warning/re-confirmation prompt should be shown.
     *
     * @param array<string, mixed> $parsedValue
     * @return array{flagged: bool, flag_reason: ?string}
     */
    public function recordAnswer(
        FarmPlan $plan,
        string $questionKey,
        string $answerText,
        array $parsedValue = []
    ): array {
        $questionDef = EnterpriseQuestionBank::query()
            ->where('enterprise_id', '=', $plan->enterprise_id)
            ->where('question_key', '=', $questionKey)
            ->first();

        $flagged = false;
        $flagReason = null;

        if ($questionDef !== null && is_array($questionDef->validation_rules_json)) {
            $rules = $questionDef->validation_rules_json;

            if (isset($rules['max_cycles_per_year']) && is_numeric($answerText)) {
                $cycles = (int) $answerText;
                if ($cycles > (int) $rules['max_cycles_per_year']) {
                    $flagged = true;
                    $flagReason = "Entered {$cycles} cycles/year exceeds typical maximum ({$rules['max_cycles_per_year']}) allowing biosecurity downtime. Flagged for review.";
                }
            }

            if (($rules['requires_irrigation'] ?? false) === true && in_array(strtolower(trim($answerText)), ['no', 'rainfed', 'none', 'kwete'], true)) {
                $flagged = true;
                $flagReason = 'This enterprise/season normally requires reliable irrigation in Zimbabwe. Marked as high-risk assumption.';
            }
        }

        PlanInterviewAnswer::query()->updateOrCreate(
            [
                'farm_plan_id' => $plan->id,
                'question_key' => $questionKey,
            ],
            [
                'answer_text' => $answerText,
                'parsed_value_json' => $parsedValue,
                'is_flagged_assumption' => $flagged,
                'flag_reason' => $flagReason,
                'answered_at' => now(),
            ]
        );

        return [
            'flagged' => $flagged,
            'flag_reason' => $flagReason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConfirmationSummary(FarmPlan $plan): array
    {
        $answers = $plan->interviewAnswers()->get()->mapWithKeys(fn (PlanInterviewAnswer $a) => [
            $a->question_key => [
                'answer' => $a->answer_text,
                'flagged' => $a->is_flagged_assumption,
                'flag_reason' => $a->flag_reason,
            ],
        ])->all();

        return [
            'farm_plan_id' => $plan->id,
            'title' => $plan->title,
            'enterprise' => $plan->enterprise?->name_en,
            'scale' => "{$plan->scale_value} {$plan->scale_unit}",
            'currency' => $plan->currency->value,
            'answers' => $answers,
        ];
    }
}
