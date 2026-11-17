<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\DTOs\CandidateDiagnosisData;
use App\DTOs\ClarifyingQuestionData;
use App\Enums\LanguageCode;
use App\Models\Diagnosis;
use App\Models\DiagnosisQuestion;
use App\Models\Tenant;

/**
 * Step 3: Adaptive Clarifying Question Engine (ONE AT A TIME).
 *
 * Rules:
 * 1. If top candidate confidence >= 0.80: if crop, growth stage, and field size/Pfumvudza count are known, exit to advisory;
 *    otherwise ask the missing mandatory context item.
 * 2. Else if questions_asked >= 5: if confidence >= tenant minimum (0.55), issue advisory with stated uncertainty;
 *    otherwise escalate to the human agronomist/vet queue.
 * 3. Else score unasked questions in the category bank by discriminative power between Candidate #1 and Candidate #2,
 *    pick the best, localise it (en/sn/nd), and attach 2-4 quick-reply options.
 */
final class AdaptiveQuestionEngine
{
    public function __construct(
        private readonly QuestionBankRepository $questionBank
    ) {
    }

    /**
     * Evaluate whether we should stop asking questions, escalate, or ask the next question.
     *
     * @return array{
     *   decision: 'ask_question' | 'proceed_to_advisory' | 'escalate_low_confidence' | 'escalate_high_stakes',
     *   question?: ClarifyingQuestionData,
     *   stated_uncertainty?: bool
     * }
     */
    public function evaluateNextStep(
        Diagnosis $diagnosis,
        Tenant $tenant,
        LanguageCode $language
    ): array {
        $highThreshold = $tenant->highConfidenceThreshold();
        $minThreshold = $tenant->minConfidenceThreshold();
        $maxQuestions = $tenant->maxClarifyingQuestions();

        $confidence = (float) $diagnosis->confidence;
        $askedCount = (int) $diagnosis->questions_asked_count;

        /** @var list<string> $askedKeys */
        $askedKeys = $diagnosis->questions()->pluck('question_key')->map(fn ($k) => (string) $k)->all();

        // 1. If confidence >= highThreshold (0.80), verify mandatory context (field size / Pfumvudza & growth stage)
        if ($confidence >= $highThreshold) {
            $missingMandatory = $this->selectMissingMandatoryContextQuestion($diagnosis, $askedKeys, $askedCount + 1, $language);
            if ($missingMandatory !== null && $askedCount < $maxQuestions) {
                $this->persistAskedQuestion($diagnosis, $missingMandatory);

                return [
                    'decision' => 'ask_question',
                    'question' => $missingMandatory,
                ];
            }

            return [
                'decision' => 'proceed_to_advisory',
                'stated_uncertainty' => false,
            ];
        }

        // 2. If questions_asked >= maxQuestions (5), check if confidence >= minThreshold (0.55)
        if ($askedCount >= $maxQuestions) {
            if ($confidence >= $minThreshold) {
                return [
                    'decision' => 'proceed_to_advisory',
                    'stated_uncertainty' => true,
                ];
            }

            return [
                'decision' => 'escalate_low_confidence',
            ];
        }

        // 3. Pick highest-information-gain unasked discriminative question from category bank
        $nextQuestion = $this->selectBestDiscriminativeQuestion($diagnosis, $askedKeys, $askedCount + 1, $language)
            ?? $this->selectMissingMandatoryContextQuestion($diagnosis, $askedKeys, $askedCount + 1, $language);

        if ($nextQuestion === null) {
            // No unasked questions remain
            if ($confidence >= $minThreshold) {
                return [
                    'decision' => 'proceed_to_advisory',
                    'stated_uncertainty' => $confidence < $highThreshold,
                ];
            }

            return [
                'decision' => 'escalate_low_confidence',
            ];
        }

        $this->persistAskedQuestion($diagnosis, $nextQuestion);

        return [
            'decision' => 'ask_question',
            'question' => $nextQuestion,
        ];
    }

    /**
     * Record the farmer's answer to the current pending question and re-score candidate confidences.
     *
     * @return array{escalate_high_stakes: bool, new_confidence: float}
     */
    public function recordAnswerAndRescore(Diagnosis $diagnosis, string $farmerAnswer): array
    {
        /** @var DiagnosisQuestion|null $pendingQuestion */
        $pendingQuestion = $diagnosis->questions()
            ->whereNull('answered_at')
            ->orderByDesc('question_order')
            ->first();

        if ($pendingQuestion === null) {
            return [
                'escalate_high_stakes' => false,
                'new_confidence' => (float) $diagnosis->confidence,
            ];
        }

        $answerClean = strtolower(trim($farmerAnswer));

        // Handle mandatory context updates (field_size_or_pfumvudza, crop_growth_stage)
        if ($pendingQuestion->question_key === 'field_size_or_pfumvudza') {
            $this->applyFieldSizeAnswer($diagnosis, $answerClean, (array) ($pendingQuestion->options_json ?? []));
        } elseif ($pendingQuestion->question_key === 'crop_growth_stage') {
            $diagnosis->update(['growth_stage' => $farmerAnswer]);
        }

        // Re-score candidates based on selected option
        $candidatesRaw = (array) ($diagnosis->candidates_json ?? []);
        $candidates = array_map(fn (array $c) => CandidateDiagnosisData::fromArray($c), $candidatesRaw);

        $options = (array) ($pendingQuestion->options_json ?? []);
        $matchedOption = $this->matchSelectedOption($answerClean, $options);

        $boostTarget = isset($matchedOption['boosts_candidate']) ? (string) $matchedOption['boosts_candidate'] : null;
        $delta = isset($matchedOption['confidence_delta']) ? (float) $matchedOption['confidence_delta'] : 0.10;
        $triggersEscalation = (bool) ($matchedOption['triggers_escalation'] ?? false);

        if ($candidates !== []) {
            $updatedCandidates = [];
            foreach ($candidates as $idx => $cand) {
                $newConf = $cand->confidence;
                if ($boostTarget !== null) {
                    if ($cand->code === $boostTarget) {
                        $newConf = min(0.96, $cand->confidence + $delta);
                    } else {
                        $newConf = max(0.02, $cand->confidence - ($delta * 0.5));
                    }
                } elseif ($idx === 0) {
                    // Confirming context/symptom question boosts top candidate
                    $newConf = min(0.95, $cand->confidence + $delta);
                }

                $updatedCandidates[] = new CandidateDiagnosisData(
                    code: $cand->code,
                    name: $cand->name,
                    localNames: $cand->localNames,
                    type: $cand->type,
                    confidence: round($newConf, 4),
                    visibleEvidence: $cand->visibleEvidence,
                    lookAlikes: $cand->lookAlikes,
                );
            }

            usort($updatedCandidates, fn (CandidateDiagnosisData $a, CandidateDiagnosisData $b) => $b->confidence <=> $a->confidence);
            $top = $updatedCandidates[0];

            $pendingQuestion->update([
                'farmer_answer' => $farmerAnswer,
                'confidence_after' => $top->confidence,
                'answered_at' => now(),
            ]);

            $diagnosis->update([
                'candidates_json' => array_map(fn (CandidateDiagnosisData $c) => $c->toArray(), $updatedCandidates),
                'final_diagnosis_name' => $top->name,
                'final_diagnosis_code' => $top->code,
                'confidence' => $top->confidence,
                'questions_asked_count' => $diagnosis->questions()->whereNotNull('answered_at')->count(),
            ]);

            return [
                'escalate_high_stakes' => $triggersEscalation,
                'new_confidence' => $top->confidence,
            ];
        }

        $pendingQuestion->update([
            'farmer_answer' => $farmerAnswer,
            'confidence_after' => $diagnosis->confidence,
            'answered_at' => now(),
        ]);

        return [
            'escalate_high_stakes' => $triggersEscalation,
            'new_confidence' => (float) $diagnosis->confidence,
        ];
    }

    /**
     * @param list<string> $askedKeys
     */
    private function selectBestDiscriminativeQuestion(
        Diagnosis $diagnosis,
        array $askedKeys,
        int $nextOrder,
        LanguageCode $language
    ): ?ClarifyingQuestionData {
        $bank = $this->questionBank->questionsForCategory($diagnosis->category);
        $candidates = (array) ($diagnosis->candidates_json ?? []);
        $cand1Code = (string) ($candidates[0]['code'] ?? '');
        $cand2Code = (string) ($candidates[1]['code'] ?? '');

        $bestQuestion = null;
        $bestScore = -1;

        foreach ($bank as $q) {
            if (in_array($q['key'], $askedKeys, true)) {
                continue;
            }

            $score = 1;
            $discriminates = $q['discriminates'];
            if (in_array($cand1Code, $discriminates, true)) {
                $score += 3;
            }
            if ($cand2Code !== '' && in_array($cand2Code, $discriminates, true)) {
                $score += 3;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestQuestion = $q;
            }
        }

        if ($bestQuestion === null) {
            return null;
        }

        return new ClarifyingQuestionData(
            questionOrder: $nextOrder,
            questionKey: $bestQuestion['key'],
            questionText: $this->questionBank->localize($bestQuestion['text'], $language),
            options: $bestQuestion['options'],
            isMandatoryContext: false,
        );
    }

    /**
     * @param list<string> $askedKeys
     */
    private function selectMissingMandatoryContextQuestion(
        Diagnosis $diagnosis,
        array $askedKeys,
        int $nextOrder,
        LanguageCode $language
    ): ?ClarifyingQuestionData {
        $mandatory = $this->questionBank->mandatoryContextQuestions();

        if (
            $diagnosis->field_size_ha === null
            && $diagnosis->pfumvudza_plots_count === null
            && !in_array('field_size_or_pfumvudza', $askedKeys, true)
        ) {
            $q = $mandatory['field_size_or_pfumvudza'];

            return new ClarifyingQuestionData(
                questionOrder: $nextOrder,
                questionKey: 'field_size_or_pfumvudza',
                questionText: $this->questionBank->localize($q['text'], $language),
                options: $q['options'],
                isMandatoryContext: true,
            );
        }

        if (empty($diagnosis->growth_stage) && !in_array('crop_growth_stage', $askedKeys, true)) {
            $q = $mandatory['crop_growth_stage'];

            return new ClarifyingQuestionData(
                questionOrder: $nextOrder,
                questionKey: 'crop_growth_stage',
                questionText: $this->questionBank->localize($q['text'], $language),
                options: $q['options'],
                isMandatoryContext: true,
            );
        }

        return null;
    }

    private function persistAskedQuestion(Diagnosis $diagnosis, ClarifyingQuestionData $question): void
    {
        DiagnosisQuestion::query()->updateOrCreate(
            [
                'diagnosis_id' => $diagnosis->id,
                'question_order' => $question->questionOrder,
            ],
            [
                'tenant_id' => $diagnosis->tenant_id,
                'question_key' => $question->questionKey,
                'question_text' => $question->questionText,
                'options_json' => $question->options,
                'confidence_before' => $diagnosis->confidence,
                'asked_at' => now(),
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $options
     * @return array<string, mixed>
     */
    private function matchSelectedOption(string $answerClean, array $options): array
    {
        // Match by option ID, 1-based index ("1", "2", "3"), or title substring
        foreach ($options as $idx => $opt) {
            $oneBased = (string) ($idx + 1);
            $id = strtolower((string) ($opt['id'] ?? ''));
            $title = strtolower((string) ($opt['title'] ?? ''));

            if ($answerClean === $oneBased || $answerClean === $id || ($title !== '' && str_contains($answerClean, $title))) {
                return $opt;
            }
        }

        return $options[0] ?? [];
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function applyFieldSizeAnswer(Diagnosis $diagnosis, string $answerClean, array $options): void
    {
        $matched = $this->matchSelectedOption($answerClean, $options);

        if (isset($matched['field_size_ha'])) {
            $diagnosis->update([
                'field_size_ha' => (float) $matched['field_size_ha'],
                'pfumvudza_plots_count' => (int) ($matched['pfumvudza_plots'] ?? 0),
            ]);

            return;
        }

        if (preg_match('/(\d+(?:\.\d+)?)\s*(ha|hectare)/i', $answerClean, $m) === 1) {
            $diagnosis->update(['field_size_ha' => (float) $m[1]]);
        } elseif (preg_match('/(\d+)\s*pfumvudza/i', $answerClean, $m) === 1) {
            $plots = (int) $m[1];
            $diagnosis->update([
                'pfumvudza_plots_count' => $plots,
                'field_size_ha' => round($plots * 0.0624, 4),
            ]);
        } else {
            $diagnosis->update(['field_size_ha' => 1.00]);
        }
    }
}
