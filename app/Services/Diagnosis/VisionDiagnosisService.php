<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\Contracts\AiProviderContract;
use App\DTOs\CandidateDiagnosisData;
use App\DTOs\VisionIdentificationResultData;
use App\Enums\DiagnosisCategory;
use App\Models\Attachment;
use App\Models\Conversation;
use App\Models\Crop;
use App\Models\Diagnosis;
use App\Models\Livestock;
use App\Services\Localization\ZimbabweAgronomyGlossary;
use Illuminate\Support\Facades\Storage;

final class VisionDiagnosisService
{
    /**
     * Notifiable or acute high-stakes conditions requiring immediate human agronomist/vet escalation.
     *
     * @var list<string>
     */
    private const HIGH_STAKES_CODES = [
        'theileriosis_january_disease',
        'foot_and_mouth_disease',
        'anthrax',
        'newcastle_disease',
        'african_armyworm_outbreak',
    ];

    public function __construct(
        private readonly AiProviderContract $aiProvider,
        private readonly ZimbabweAgronomyGlossary $glossary
    ) {
    }

    /**
     * Step 2: Execute first-pass identification from an Attachment image and persist Diagnosis.
     */
    public function identifyFromAttachment(
        Conversation $conversation,
        Attachment $attachment,
        ?string $captionOrContext = null
    ): VisionIdentificationResultData {
        $rawBytes = Storage::disk($attachment->disk)->get($attachment->file_path) ?? '';
        $base64Image = base64_encode($rawBytes);

        $systemPrompt = $this->buildVisionSystemPrompt();
        $userPrompt = $captionOrContext !== null && trim($captionOrContext) !== ''
            ? "Farmer note: {$captionOrContext}\nIdentify the agricultural problem and return the strict JSON schema."
            : 'Analyze this Zimbabwean farm photo and return the strict JSON schema with top 3 ranked candidates.';

        $rawResult = $this->aiProvider->analyzeImageForDiagnosis(
            base64Image: $base64Image,
            mimeType: $attachment->mime_type,
            systemPrompt: $systemPrompt,
            userPrompt: $userPrompt,
            context: [
                'language' => $conversation->language->value,
                'context_chip' => $conversation->context_chip,
            ]
        );

        return $this->parseAndPersistIdentification(
            conversation: $conversation,
            rawResult: $rawResult,
            attachmentId: $attachment->id,
            defaultImageQuality: 'good'
        );
    }

    /**
     * Parse text/voice symptom description when farmer has no photo or after 2 poor photo retries.
     */
    public function identifyFromTextSymptoms(
        Conversation $conversation,
        string $symptomDescription
    ): VisionIdentificationResultData {
        $rawResult = $this->aiProvider->analyzeImageForDiagnosis(
            base64Image: '',
            mimeType: 'text/plain',
            systemPrompt: $this->buildVisionSystemPrompt(),
            userPrompt: "Text symptom description from farmer: {$symptomDescription}",
            context: [
                'language' => $conversation->language->value,
                'text_only' => true,
            ]
        );

        $rawResult['image_quality'] = 'text_only';

        // Cap initial text-only confidence below 0.80 so clarifying questions are always asked
        if (isset($rawResult['candidates']) && is_array($rawResult['candidates'])) {
            foreach ($rawResult['candidates'] as $idx => $cand) {
                $rawResult['candidates'][$idx]['confidence'] = min(
                    0.75,
                    (float) ($cand['confidence'] ?? 0.65)
                );
            }
        }

        return $this->parseAndPersistIdentification(
            conversation: $conversation,
            rawResult: $rawResult,
            attachmentId: null,
            defaultImageQuality: 'text_only'
        );
    }

    /**
     * Format the first-pass identification message for the farmer.
     * Enforces: Never present a single answer as certain if confidence < 0.80.
     */
    public function formatFirstPassSummary(
        VisionIdentificationResultData $result,
        float $highConfidenceThreshold = 0.80
    ): string {
        $top = $result->topCandidate();
        if ($top === null) {
            return 'We need a bit more information to identify the problem on your crop or livestock.';
        }

        $pct = (int) round($top->confidence * 100);
        $localSn = $top->localNames['sn'] ?: $top->name;

        if ($top->confidence < $highConfidenceThreshold) {
            $runnerUp = $result->candidates[1]->name ?? ($top->lookAlikes[0] ?? 'a look-alike condition');

            return "Likely *{$top->name}* ({$localSn} — {$pct}% confidence), or possibly *{$runnerUp}*. Confirm with field symptoms before treatment.";
        }

        return "Identified: *{$top->name}* ({$localSn} — {$pct}% confidence).";
    }

    /**
     * @param array<string, mixed> $rawResult
     */
    private function parseAndPersistIdentification(
        Conversation $conversation,
        array $rawResult,
        ?string $attachmentId,
        string $defaultImageQuality
    ): VisionIdentificationResultData {
        $categoryStr = (string) ($rawResult['category'] ?? 'unclear');
        $category = DiagnosisCategory::tryFrom($categoryStr) ?? DiagnosisCategory::UNCLEAR;
        $cropStr = isset($rawResult['crop']) ? strtolower(trim((string) $rawResult['crop'])) : null;
        $growthStage = isset($rawResult['growth_stage']) ? (string) $rawResult['growth_stage'] : null;
        $imageQuality = (string) ($rawResult['image_quality'] ?? $defaultImageQuality);

        $rawCandidates = array_slice((array) ($rawResult['candidates'] ?? []), 0, 3);
        $candidates = [];
        $isHighStakes = false;

        foreach ($rawCandidates as $cand) {
            $name = (string) ($cand['name'] ?? 'Unknown');
            $code = (string) ($cand['code'] ?? strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $name)));
            $glossaryNames = $this->glossary->localNamesFor($code);

            $snName = !empty($cand['local_names']['sn']) ? (string) $cand['local_names']['sn'] : $glossaryNames['sn'];
            $ndName = !empty($cand['local_names']['nd']) ? (string) $cand['local_names']['nd'] : $glossaryNames['nd'];

            $dto = new CandidateDiagnosisData(
                code: $code,
                name: $name,
                localNames: ['sn' => $snName, 'nd' => $ndName],
                type: (string) ($cand['type'] ?? $category->value),
                confidence: (float) ($cand['confidence'] ?? 0.0),
                visibleEvidence: array_values(array_map('strval', (array) ($cand['visible_evidence'] ?? []))),
                lookAlikes: array_values(array_map('strval', (array) ($cand['look_alikes'] ?? []))),
            );
            $candidates[] = $dto;

            if (in_array($code, self::HIGH_STAKES_CODES, true)) {
                $isHighStakes = true;
            }
        }

        // Sort top 3 candidates descending by confidence
        usort($candidates, fn (CandidateDiagnosisData $a, CandidateDiagnosisData $b) => $b->confidence <=> $a->confidence);

        $missingInfo = array_values(array_map('strval', (array) ($rawResult['missing_information'] ?? [])));

        $cropModel = $cropStr !== null
            ? Crop::query()->where('code', '=', $cropStr)->orWhereRaw('LOWER(name_en) = ?', [$cropStr])->first()
            : null;
        $livestockModel = $cropStr !== null && $cropModel === null
            ? Livestock::query()->where('code', '=', $cropStr)->first()
            : null;

        $farmer = $conversation->farmer;
        $farm = $farmer?->farms()->first();
        $topCandidate = $candidates[0] ?? null;

        Diagnosis::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'farmer_id' => $conversation->farmer_id,
            'farm_id' => $farm?->id,
            'primary_attachment_id' => $attachmentId,
            'crop_id' => $cropModel?->id,
            'livestock_id' => $livestockModel?->id,
            'ward_id' => $farmer?->ward_id ?? $farm?->ward_id,
            'category' => $category,
            'growth_stage' => $growthStage,
            'field_size_ha' => $farm?->cultivated_area_ha,
            'pfumvudza_plots_count' => $farm?->pfumvudza_plots_count ?: null,
            'image_quality' => $imageQuality,
            'candidates_json' => array_map(fn (CandidateDiagnosisData $c) => $c->toArray(), $candidates),
            'missing_information_json' => $missingInfo,
            'final_diagnosis_name' => $topCandidate?->name,
            'final_diagnosis_code' => $topCandidate?->code,
            'confidence' => $topCandidate?->confidence ?? 0.0,
            'questions_asked_count' => 0,
            'status' => $imageQuality === 'poor' ? 'awaiting_better_image' : 'clarifying',
        ]);

        if ($cropModel !== null || $livestockModel !== null) {
            $conversation->refreshContextChip(
                cropName: $cropModel?->name_en ?? $livestockModel?->name_en,
                growthStage: $growthStage,
                condition: $category->value
            );
        }

        return new VisionIdentificationResultData(
            category: $category,
            crop: $cropModel?->code ?? $livestockModel?->code ?? $cropStr,
            growthStage: $growthStage,
            candidates: $candidates,
            imageQuality: $imageQuality,
            missingInformation: $missingInfo,
            isHighStakesOrNotifiable: $isHighStakes,
        );
    }

    private function buildVisionSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a senior Zimbabwean agronomist, entomologist, plant pathologist, and veterinary diagnostician.
Inspect the farmer's image and return ONLY valid JSON matching this exact structure:
{
  "category": "pest | disease | nutrient_deficiency | nutrient_toxicity | weed | abiotic_stress | livestock_condition | unclear",
  "crop": "maize | tobacco | soya_beans | cotton | sorghum | millet | groundnuts | sugar_beans | wheat | tomatoes | vegetables | citrus | bananas | macadamia | cattle | goats | poultry",
  "growth_stage": "string",
  "candidates": [
    {
      "code": "snake_case_identifier",
      "name": "Common & Scientific Name",
      "local_names": {"sn": "ChiShona name", "nd": "isiNdebele name"},
      "type": "pest | disease | nutrient_deficiency | weed | abiotic_stress | livestock_condition",
      "confidence": 0.0,
      "visible_evidence": ["specific visual symptom observed"],
      "look_alikes": ["differential diagnoses to rule out"]
    }
  ],
  "image_quality": "good | poor",
  "missing_information": ["key_needed_to_confirm"]
}
Rank up to 3 candidates by confidence (0.0 to 1.0). Be conservative: if diagnostic features separating look-alikes are not clearly visible in the photo, set confidence < 0.80 so clarifying questions are asked.
PROMPT;
    }
}
