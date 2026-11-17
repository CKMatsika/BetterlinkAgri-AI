<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\Contracts\WeatherProviderContract;
use App\DTOs\ApplicationRateCalculationData;
use App\DTOs\MatchedProductRecommendationData;
use App\DTOs\RichMessageCardData;
use App\DTOs\SprayingWindowData;
use App\DTOs\StructuredAdvisoryData;
use App\Enums\DiagnosisCategory;
use App\Enums\LanguageCode;
use App\Enums\RichCardType;
use App\Models\Advisory;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Tenant;
use App\Models\Ward;
use App\Services\Localization\ZimbabweAgronomyGlossary;
use App\Services\Rag\TenantRagService;

/**
 * Step 4 & Step 10: Grounded Advisory & Rich Card Generator Service.
 *
 * Generates:
 * - Advisory A: Pest or Disease (plain language + local name, scouting threshold, cultural controls first,
 *   up to 3 approved catalogue products with USD/ZiG prices, 16L knapsack & ha doses, mixing order, timing,
 *   interval, PHI, REI, PPE, resistance rotation, label source citation, ward weather spraying window,
 *   Today / This Week / +7d action steps).
 * - Advisory B: Nutrient Deficiency (nutrient + evidence, differential causes to rule out, fix now + season plan,
 *   catalogue fertiliser, 3-way rates from ApplicationRateCalculator [per plant, per Pfumvudza station,
 *   per ha + 50kg bags] with working shown, placement/timing/precautions, Natural Region variety advice,
 *   10-14d photo check-in).
 * - Advisory C: Weed, Abiotic Stress, or Livestock condition.
 */
final class AdvisoryGeneratorService
{
    public function __construct(
        private readonly ProductMatcherService $productMatcher,
        private readonly ApplicationRateCalculator $rateCalculator,
        private readonly TenantRagService $ragService,
        private readonly WeatherProviderContract $weatherService,
        private readonly SafetyGuardrailValidator $guardrailValidator,
        private readonly ZimbabweAgronomyGlossary $glossary
    ) {
    }

    public function generateGroundedAdvisory(
        Tenant $tenant,
        Conversation $conversation,
        Diagnosis $diagnosis,
        bool $statedUncertainty = false
    ): StructuredAdvisoryData {
        $language = $conversation->language;
        $headings = $this->glossary->advisoryHeadings($language);
        $fieldSizeHa = (float) ($diagnosis->field_size_ha ?: 1.0);
        $pfumvudzaPlots = $diagnosis->pfumvudza_plots_count !== null && $diagnosis->pfumvudza_plots_count > 0
            ? (int) $diagnosis->pfumvudza_plots_count
            : null;

        $targetCode = (string) ($diagnosis->final_diagnosis_code ?: 'fall_armyworm');
        $targetName = (string) ($diagnosis->final_diagnosis_name ?: 'Crop Issue');
        $localNames = $this->glossary->localNamesFor($targetCode);
        $localLabel = $language === LanguageCode::NDEBELE ? $localNames['nd'] : $localNames['sn'];
        $confidencePct = (int) round(((float) $diagnosis->confidence) * 100);

        // Retrieve supporting RAG chunks from tenant knowledge base
        $ragChunks = $this->ragService->search(
            tenant: $tenant,
            query: "{$targetName} {$targetCode}",
            cropCode: $diagnosis->crop?->code,
            topK: 3
        );
        $ragChunkIds = array_map(fn (array $c) => $c['chunk_id'], $ragChunks);

        // Fetch Ward Weather Spraying Window
        $ward = $diagnosis->ward ?? Ward::query()->first();
        $weatherWindow = $ward !== null
            ? $this->weatherService->getSprayingWindowForWard($ward)
            : null;

        if (in_array($diagnosis->category, [DiagnosisCategory::NUTRIENT_DEFICIENCY, DiagnosisCategory::NUTRIENT_TOXICITY], true)) {
            $advisoryDto = $this->buildNutrientAdvisory(
                tenant: $tenant,
                conversation: $conversation,
                diagnosis: $diagnosis,
                targetCode: $targetCode,
                targetName: $targetName,
                localLabel: $localLabel,
                confidencePct: $confidencePct,
                fieldSizeHa: $fieldSizeHa,
                pfumvudzaPlots: $pfumvudzaPlots,
                headings: $headings,
                language: $language,
                statedUncertainty: $statedUncertainty,
                ragChunkIds: $ragChunkIds
            );
        } else {
            $advisoryDto = $this->buildPestDiseaseOrLivestockAdvisory(
                tenant: $tenant,
                conversation: $conversation,
                diagnosis: $diagnosis,
                targetCode: $targetCode,
                targetName: $targetName,
                localLabel: $localLabel,
                confidencePct: $confidencePct,
                fieldSizeHa: $fieldSizeHa,
                headings: $headings,
                weatherWindow: $weatherWindow,
                language: $language,
                statedUncertainty: $statedUncertainty,
                ragChunkIds: $ragChunkIds
            );
        }

        // Persist Advisory record
        Advisory::query()->updateOrCreate(
            ['diagnosis_id' => $diagnosis->id],
            [
                'tenant_id' => $tenant->id,
                'advisory_type' => $advisoryDto->advisoryType,
                'steps_json' => $advisoryDto->steps,
                'products_json' => array_map(fn (MatchedProductRecommendationData $p) => $p->toArray(), $advisoryDto->products),
                'rates_json' => array_map(fn (ApplicationRateCalculationData $r) => $r->toArray(), $advisoryDto->rates),
                'weather_window_json' => $advisoryDto->weatherWindow?->toArray(),
                'safety_warnings_text' => $advisoryDto->safetySection,
                'rendered_message_text' => $advisoryDto->renderedWhatsAppText,
                'guardrail_passed' => !$advisoryDto->requiresEscalation,
            ]
        );

        $diagnosis->update([
            'status' => $advisoryDto->requiresEscalation ? 'escalated' : 'advisory_issued',
        ]);

        return $advisoryDto;
    }

    /**
     * @param array<string, string> $headings
     * @param list<string> $ragChunkIds
     */
    private function buildPestDiseaseOrLivestockAdvisory(
        Tenant $tenant,
        Conversation $conversation,
        Diagnosis $diagnosis,
        string $targetCode,
        string $targetName,
        string $localLabel,
        int $confidencePct,
        float $fieldSizeHa,
        array $headings,
        ?SprayingWindowData $weatherWindow,
        LanguageCode $language,
        bool $statedUncertainty,
        array $ragChunkIds
    ): StructuredAdvisoryData {
        $matchedProducts = $this->productMatcher->findApprovedProducts(
            tenant: $tenant,
            targetCode: $targetCode,
            cropId: $diagnosis->crop_id ? (string) $diagnosis->crop_id : null,
            livestockId: $diagnosis->livestock_id ? (string) $diagnosis->livestock_id : null,
            limit: 3
        );

        $rates = [];
        foreach ($matchedProducts as $prod) {
            $rates[] = $this->rateCalculator->calculateSprayChemicalRate($prod, $fieldSizeHa);
        }

        $guardrailResult = $this->guardrailValidator->validateAdvisoryPayload(
            tenant: $tenant,
            diagnosis: $diagnosis,
            matchedProducts: $matchedProducts,
            calculatedRates: $rates
        );

        $uncertaintyPrefix = $statedUncertainty
            ? 'Likely diagnosis (confirm symptoms in field before spraying): '
            : 'Confirmed Diagnosis: ';

        $summaryLine = "🌿 *{$uncertaintyPrefix}{$targetName}* ({$localLabel} — {$confidencePct}% confidence)";

        $whatItIs = "{$targetName} ({$localLabel}). Can cause severe leaf/yield loss if unchecked during the {$diagnosis->growth_stage} stage.";

        $whatToDoNow = "• *Scouting threshold:* Inspect 20 plants across the field in a W-pattern; treat immediately if threshold is exceeded.\n"
            . "• *Cultural & non-chemical steps first:* Remove heavily infested plant debris, practice crop rotation, keep field weed-free, and crush visible egg masses.";

        if (!$guardrailResult->passed || $guardrailResult->escalateNoRegisteredProduct) {
            $productSection = 'No registered chemical product in our current catalogue matches this exact crop and target. Do NOT apply unregistered chemicals or tank mixes. We have escalated your case to our human Agronomist/Vet team.';
            $howToApply = 'Continue cultural sanitation and wait for our Agronomist callback.';
            $requiresEscalation = true;
            $escalationReason = implode('; ', $guardrailResult->violations);
        } else {
            $productLines = [];
            foreach ($matchedProducts as $idx => $prod) {
                $num = $idx + 1;
                $packStrings = [];
                foreach ($prod->packSizes as $pk) {
                    $packStrings[] = sprintf('%s: US$%.2f / ZiG %.2f', $pk['size'], $pk['price_usd'], $pk['price_zig']);
                }
                $packsJoined = implode(' | ', $packStrings);
                $citation = $prod->citedPageNumber !== null
                    ? "{$prod->citedDocumentTitle} (p. {$prod->citedPageNumber})"
                    : $prod->citedDocumentTitle;

                $productLines[] = "{$num}. *{$prod->productName}* ({$prod->activeIngredient} — {$prod->modeOfActionGroup})\n"
                    . "   • Reg No: {$prod->registrationNumber} | Packs: {$packsJoined}\n"
                    . "   • Dose: *{$prod->dosePer16LKnapsackValue} {$prod->dosePer16LKnapsackUnit} per 16 L knapsack* (*{$prod->dosePerHaValue} {$prod->dosePerHaUnit}/ha*)\n"
                    . "   • PHI: {$prod->phiDays} days | REI: {$prod->reiHours} hours | Re-apply interval: " . ($prod->reapplicationIntervalDays ?? 10) . " days\n"
                    . "   • Source: {$citation}";
            }
            $productSection = implode("\n\n", $productLines);

            $primaryProd = $matchedProducts[0];
            $primaryRate = $rates[0];
            $weatherText = $weatherWindow?->recommendedWindowSummary ?? 'Spray early morning (06:00–09:00) or late afternoon when wind is calm (<15 km/h) and no rain is expected for 4 hours.';

            $howToApply = "✓ *Mixing Order:* {$primaryProd->mixingOrder}\n"
                . "✓ *Timing:* {$primaryProd->applicationTimingNotes}\n"
                . "✓ *Working ({$fieldSizeHa} ha):* " . implode(' ', $primaryRate->workingSteps) . "\n"
                . "✓ *Resistance Management:* Rotate between different IRAC/FRAC Mode of Action groups on subsequent sprays.\n"
                . "✓ *Weather Window:* {$weatherText}\n"
                . "✓ *Action Timeline:*\n"
                . "   - *Today:* Scout field & secure approved product + PPE.\n"
                . "   - *This Week:* Spray during the safe morning window.\n"
                . "   - *In 7 Days:* Send us a follow-up photo to verify control.";

            $requiresEscalation = false;
            $escalationReason = null;
        }

        $safetySection = $this->guardrailValidator->buildMandatorySafetyDisclaimer($tenant, $matchedProducts, $language);
        $nextStepSection = 'Would you like us to find the nearest stocked outlet to hold these products for you, or connect you with a field representative? (Reply *1* for Nearest Stocked Outlets, *2* to Speak to a Rep)';

        $renderedWhatsApp = implode("\n\n", [
            $summaryLine,
            "{$headings['what_it_is']}\n{$whatItIs}",
            "{$headings['what_to_do_now']}\n{$whatToDoNow}",
            "{$headings['product_options']}\n{$productSection}",
            "{$headings['how_to_apply']}\n{$howToApply}",
            "{$headings['safety']}\n{$safetySection}",
            "{$headings['next_step']}\n{$nextStepSection}",
        ]);

        $contextChip = $conversation->context_chip ?? "{$diagnosis->crop?->name_en} / {$diagnosis->growth_stage} / {$diagnosis->category->value}";

        $richCards = [
            new RichMessageCardData(
                cardType: RichCardType::DIAGNOSIS_RESULT,
                title: $targetName,
                contextChip: $contextChip,
                payload: [
                    'target_code' => $targetCode,
                    'local_name' => $localLabel,
                    'confidence_pct' => $confidencePct,
                    'stated_uncertainty' => $statedUncertainty,
                    'what_it_is' => $whatItIs,
                ]
            ),
        ];

        if ($matchedProducts !== []) {
            $richCards[] = new RichMessageCardData(
                cardType: RichCardType::RECOMMENDED_PRODUCT,
                title: 'Approved Portfolio Options',
                contextChip: $contextChip,
                payload: [
                    'products' => array_map(fn (MatchedProductRecommendationData $p) => $p->toArray(), $matchedProducts),
                ]
            );
            $richCards[] = new RichMessageCardData(
                cardType: RichCardType::APPLICATION_GUIDANCE,
                title: 'Application & Safety Tick-List',
                contextChip: $contextChip,
                payload: [
                    'how_to_apply' => $howToApply,
                    'weather_window' => $weatherWindow?->toArray(),
                    'safety' => $safetySection,
                ]
            );
        }

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis->id,
            'event_type' => 'ai_diagnosis_turn',
            'candidates_json' => $diagnosis->candidates_json,
            'questions_json' => $diagnosis->questions()->get()->toArray(),
            'rag_chunk_ids_json' => $ragChunkIds,
            'guardrail_result_json' => $guardrailResult->toArray(),
            'created_at' => now(),
        ]);

        return new StructuredAdvisoryData(
            advisoryType: $requiresEscalation ? 'non_chemical_escalation' : $diagnosis->category->value,
            summaryLine: $summaryLine,
            whatItIsSection: $whatItIs,
            whatToDoNowSection: $whatToDoNow,
            productOptionsSection: $productSection,
            howToApplySection: $howToApply,
            safetySection: $safetySection,
            nextStepSection: $nextStepSection,
            renderedWhatsAppText: $renderedWhatsApp,
            steps: [
                'today' => 'Scout field & acquire approved product + PPE',
                'this_week' => 'Apply during safe morning weather window',
                'follow_up_days' => 7,
            ],
            products: $matchedProducts,
            rates: $rates,
            weatherWindow: $weatherWindow,
            richCards: $richCards,
            requiresEscalation: $requiresEscalation,
            escalationReason: $escalationReason,
        );
    }

    /**
     * @param array<string, string> $headings
     * @param list<string> $ragChunkIds
     */
    private function buildNutrientAdvisory(
        Tenant $tenant,
        Conversation $conversation,
        Diagnosis $diagnosis,
        string $targetCode,
        string $targetName,
        string $localLabel,
        int $confidencePct,
        float $fieldSizeHa,
        ?int $pfumvudzaPlots,
        array $headings,
        LanguageCode $language,
        bool $statedUncertainty,
        array $ragChunkIds
    ): StructuredAdvisoryData {
        $nutrientSymbol = match (true) {
            str_contains($targetCode, 'phosphorus') => 'P',
            str_contains($targetCode, 'potassium') => 'K',
            str_contains($targetCode, 'lime') || str_contains($targetCode, 'calcium') => 'Ca_Lime',
            default => 'N',
        };

        $crop = $diagnosis->crop;
        $rates = [];

        if ($crop !== null) {
            $primaryRate = $this->rateCalculator->calculateFertiliserRate(
                tenant: $tenant,
                crop: $crop,
                nutrientAddressed: $nutrientSymbol,
                fieldSizeHa: $fieldSizeHa,
                pfumvudzaPlotsCount: $pfumvudzaPlots,
                naturalRegion: $diagnosis->ward?->natural_region?->value
            );
            if ($primaryRate !== null) {
                $rates[] = $primaryRate;
            }
        }

        $guardrailResult = $this->guardrailValidator->validateAdvisoryPayload(
            tenant: $tenant,
            diagnosis: $diagnosis,
            matchedProducts: [],
            calculatedRates: $rates
        );

        $naturalRegion = $diagnosis->ward?->natural_region;
        $nrAdvice = $naturalRegion !== null
            ? "For Natural Region {$naturalRegion->value} ({$naturalRegion->annualRainfallMm()}), plant {$naturalRegion->recommendedMaizeMaturityGroup()} and split top-dressing on sandy soils."
            : 'Match hybrid maturity group to your Natural Region rainfall and split Nitrogen top-dressing on sandy soils.';

        $summaryLine = "🌱 *Nutrient Diagnosis: {$targetName}* ({$localLabel} — {$confidencePct}% confidence)";
        $whatItIs = "{$targetName} ({$localLabel}). Other causes to rule out: low soil pH (<5.2 CaCl2 locking up nutrients), waterlogging leaching Nitrogen, Maize Streak Virus, or herbicide carryover.";
        $whatToDoNow = "• *Fix Now:* Apply recommended top-dressing or foliar feed only when soil has adequate moisture.\n"
            . "• *Season Plan:* Combine basal Compound D (7:14:7) at planting with Agricultural Dolomitic Lime on acidic granite sands.\n"
            . "• *Variety & Management:* {$nrAdvice}";

        if ($rates === []) {
            $productSection = 'Consult our agronomist for a custom soil test fertiliser blend.';
            $howToApply = 'Take a composite soil sample (0–20 cm depth) for pH and nutrient analysis.';
            $requiresEscalation = true;
            $escalationReason = 'No deterministic fertiliser rate row matched.';
        } else {
            $rate = $rates[0];
            $productSection = "*{$rate->productOrFertiliserName}*\n"
                . "• Per Plant: *{$rate->rateGramsPerPlant} g/plant* ({$rate->localMeasurePerPlant})\n"
                . "• Per Pfumvudza Station (1,456 holes/plot): *{$rate->rateGramsPerPfumvudzaStation} g/station* (*{$rate->totalKgPerPfumvudzaPlot} kg per Pfumvudza plot*)\n"
                . "• Per Hectare & Field ({$rate->fieldSizeHa} ha): *{$rate->rateKgPerHa} kg/ha* (*{$rate->bags50KgPerHa} × 50 kg bags/ha*) -> *{$rate->totalKgForField} kg* (*{$rate->bags50KgForField} × 50 kg bags* for your field)";

            $howToApply = "✓ *Placement Method:* {$rate->placementMethod} (keep 5–7 cm away from the stem to prevent fertiliser burn).\n"
                . "✓ *Precautions:* {$rate->precautions}\n"
                . "✓ *Working Shown:*\n   " . implode("\n   ", $rate->workingSteps) . "\n"
                . "✓ *Follow-Up Check:* Send us a photo in *10–14 days* to check leaf green-up and recovery.";
            $requiresEscalation = false;
            $escalationReason = null;
        }

        $safetySection = '⚠️ *FERTILISER HANDLING SAFETY:* Wear gloves and a dust mask when handling lime or granular fertilisers. Store bags off the floor in a dry, locked shed away from children and livestock.';
        $nextStepSection = 'Would you like to see the nearest outlets with this fertiliser in stock and reserve bags? (Reply *1* for Nearest Stocked Outlets, *2* to Speak to a Rep)';

        $renderedWhatsApp = implode("\n\n", [
            $summaryLine,
            "{$headings['what_it_is']}\n{$whatItIs}",
            "{$headings['what_to_do_now']}\n{$whatToDoNow}",
            "{$headings['product_options']}\n{$productSection}",
            "{$headings['how_to_apply']}\n{$howToApply}",
            "{$headings['safety']}\n{$safetySection}",
            "{$headings['next_step']}\n{$nextStepSection}",
        ]);

        $contextChip = $conversation->context_chip ?? "{$diagnosis->crop?->name_en} / {$diagnosis->growth_stage} / {$targetCode}";

        $richCards = [
            new RichMessageCardData(
                cardType: RichCardType::DIAGNOSIS_RESULT,
                title: $targetName,
                contextChip: $contextChip,
                payload: [
                    'target_code' => $targetCode,
                    'local_name' => $localLabel,
                    'confidence_pct' => $confidencePct,
                    'stated_uncertainty' => $statedUncertainty,
                    'what_it_is' => $whatItIs,
                ]
            ),
            new RichMessageCardData(
                cardType: RichCardType::APPLICATION_GUIDANCE,
                title: '3-Way Fertiliser Application Rates',
                contextChip: $contextChip,
                payload: [
                    'rates' => array_map(fn (ApplicationRateCalculationData $r) => $r->toArray(), $rates),
                    'how_to_apply' => $howToApply,
                ]
            ),
        ];

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis->id,
            'event_type' => 'ai_diagnosis_turn',
            'candidates_json' => $diagnosis->candidates_json,
            'questions_json' => $diagnosis->questions()->get()->toArray(),
            'rag_chunk_ids_json' => $ragChunkIds,
            'guardrail_result_json' => $guardrailResult->toArray(),
            'created_at' => now(),
        ]);

        return new StructuredAdvisoryData(
            advisoryType: 'nutrient',
            summaryLine: $summaryLine,
            whatItIsSection: $whatItIs,
            whatToDoNowSection: $whatToDoNow,
            productOptionsSection: $productSection,
            howToApplySection: $howToApply,
            safetySection: $safetySection,
            nextStepSection: $nextStepSection,
            renderedWhatsAppText: $renderedWhatsApp,
            steps: [
                'today' => 'Check soil moisture and acquire recommended fertiliser',
                'this_week' => 'Apply per plant / Pfumvudza station 5cm from stem',
                'follow_up_days' => 14,
            ],
            products: [],
            rates: $rates,
            weatherWindow: null,
            richCards: $richCards,
            requiresEscalation: $requiresEscalation,
            escalationReason: $escalationReason,
        );
    }
}
