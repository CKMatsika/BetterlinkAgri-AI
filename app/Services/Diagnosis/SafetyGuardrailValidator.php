<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\DTOs\ApplicationRateCalculationData;
use App\DTOs\GuardrailValidationResultData;
use App\DTOs\MatchedProductRecommendationData;
use App\Enums\DiagnosisCategory;
use App\Enums\LanguageCode;
use App\Models\Diagnosis;
use App\Models\Product;
use App\Models\ProductTarget;
use App\Models\Tenant;

/**
 * Step 5: Non-Negotiable Safety and Guardrail Validator.
 *
 * Enforces:
 * 1. Doses, rates, intervals, and registration claims come ONLY from tenant-approved documents,
 *    the catalogue (product_targets / fertiliser_recommendations), or deterministic calculators.
 * 2. Never recommend a product that is not in the catalogue or not registered for that crop and target.
 * 3. Never suggest unapproved tank mixes.
 * 4. Always include PPE, children/pregnancy/water/bee warnings, and a short pesticide-safety disclaimer.
 * 5. If no registered product or fertiliser rate is found, flag escalateNoRegisteredProduct = true.
 */
final class SafetyGuardrailValidator
{
    /**
     * @param list<MatchedProductRecommendationData> $matchedProducts
     * @param list<ApplicationRateCalculationData> $calculatedRates
     */
    public function validateAdvisoryPayload(
        Tenant $tenant,
        Diagnosis $diagnosis,
        array $matchedProducts,
        array $calculatedRates,
        string $renderedText = ''
    ): GuardrailValidationResultData {
        $violations = [];
        $verifiedSkus = [];

        $isChemicalCategory = in_array($diagnosis->category, [
            DiagnosisCategory::PEST,
            DiagnosisCategory::DISEASE,
            DiagnosisCategory::WEED,
            DiagnosisCategory::LIVESTOCK_CONDITION,
        ], true);

        $isNutrientCategory = in_array($diagnosis->category, [
            DiagnosisCategory::NUTRIENT_DEFICIENCY,
            DiagnosisCategory::NUTRIENT_TOXICITY,
        ], true);

        // 1. Check if a chemical category has zero approved/registered products
        if ($isChemicalCategory && $matchedProducts === []) {
            return new GuardrailValidationResultData(
                passed: false,
                escalateNoRegisteredProduct: true,
                mandatorySafetyDisclaimer: $this->buildMandatorySafetyDisclaimer($tenant, $matchedProducts, LanguageCode::ENGLISH),
                violations: ['No registered product found in tenant catalogue for this crop and target.'],
                verifiedSkus: [],
            );
        }

        // 2. Check if a nutrient category has zero deterministic fertiliser rates
        if ($isNutrientCategory && $calculatedRates === []) {
            return new GuardrailValidationResultData(
                passed: false,
                escalateNoRegisteredProduct: true,
                mandatorySafetyDisclaimer: $this->buildMandatorySafetyDisclaimer($tenant, [], LanguageCode::ENGLISH),
                violations: ['No deterministic fertiliser recommendation found for this crop and nutrient.'],
                verifiedSkus: [],
            );
        }

        // 3. Verify every product against DB registration and (crop, target) authorisation
        foreach ($matchedProducts as $dto) {
            /** @var Product|null $product */
            $product = Product::withoutGlobalScopes()->find($dto->productId);

            if ($product === null || !$product->isRegistrationActive()) {
                $violations[] = "Product SKU {$dto->sku} is not active or has an expired Zimbabwe registration.";
                continue;
            }

            if ($product->is_restricted_use) {
                $violations[] = "Product SKU {$dto->sku} is flagged as restricted-use and cannot be auto-recommended.";
                continue;
            }

            $targetRow = ProductTarget::withoutGlobalScopes()
                ->where('product_id', '=', $product->id)
                ->where('target_code', '=', $diagnosis->final_diagnosis_code)
                ->when($diagnosis->crop_id !== null, fn ($q) => $q->where('crop_id', '=', $diagnosis->crop_id))
                ->when($diagnosis->livestock_id !== null, fn ($q) => $q->where('livestock_id', '=', $diagnosis->livestock_id))
                ->first();

            if ($targetRow === null) {
                $violations[] = "Product SKU {$dto->sku} is not registered for target {$diagnosis->final_diagnosis_code}.";
                continue;
            }

            // Verify exact numeric dose per 16L knapsack and per ha match the DB row
            if (
                abs((float) $targetRow->dose_per_16l_knapsack_value - $dto->dosePer16LKnapsackValue) > 0.01
                || abs((float) $targetRow->dose_per_ha_value - $dto->dosePerHaValue) > 0.01
            ) {
                $violations[] = "Dose mismatch detected for SKU {$dto->sku} against database product_targets row.";
                continue;
            }

            $verifiedSkus[] = $dto->sku;
        }

        // 4. Scan rendered text for unapproved tank-mix instructions
        if ($renderedText !== '' && preg_match('/\b(tank[- ]?mix\s+with|mix\s+together\s+in\s+one\s+tank)\b/i', $renderedText) === 1) {
            $violations[] = 'Unapproved tank-mix instruction detected in advisory text.';
        }

        return new GuardrailValidationResultData(
            passed: $violations === [],
            escalateNoRegisteredProduct: false,
            mandatorySafetyDisclaimer: $this->buildMandatorySafetyDisclaimer($tenant, $matchedProducts, LanguageCode::ENGLISH),
            violations: $violations,
            verifiedSkus: $verifiedSkus,
        );
    }

    /**
     * Build the mandatory PPE, environmental, child/pregnancy, and label safety block.
     *
     * @param list<MatchedProductRecommendationData> $matchedProducts
     */
    public function buildMandatorySafetyDisclaimer(
        Tenant $tenant,
        array $matchedProducts,
        LanguageCode $language
    ): string {
        $ppeList = ['nitrile gloves', 'respirator mask', 'goggles', 'gumboots', 'long overalls'];
        if ($matchedProducts !== []) {
            $collected = [];
            foreach ($matchedProducts as $p) {
                foreach ($p->ppeRequirements as $ppe) {
                    $collected[] = str_replace('_', ' ', $ppe);
                }
            }
            if ($collected !== []) {
                $ppeList = array_values(array_unique($collected));
            }
        }

        $ppeString = implode(', ', $ppeList);
        $tenantCustomDisclaimer = (string) ($tenant->boundaries_config['mandatory_disclaimer'] ?? '');

        return match ($language) {
            LanguageCode::SHONA => "⚠️ *CHENJEDZO NEKUZVIDZIVIRIRA (PPE):*\n"
                . "• Pfekai mbatya dzekuzvidzivirira: {$ppeString}.\n"
                . "• Chengetai mishonga kure nevana vadiki, vakadzi vane pamuviri kana vanoyamwisa, uye zvipfuyo.\n"
                . "• Musagezere zvimwayiso (knapsack) pedyo nematsime, nzizi kana madhamu; chengetedzai nyuchi (musamwaye mbesa dziri kutumbuka masikati).\n"
                . "• Verengai bepa remitemo pamushonga (label) musati mavhenganisa. Chinjana-chinjanai mhando dzemishonga (IRAC/FRAC groups) kudzivirira kuzivikanwa kwemushonga nezvipuka.",
            LanguageCode::NDEBELE => "⚠️ *UKUVIKELA INGOZI (PPE):*\n"
                . "•Gqokani izigqoko zokuzivikela: {$ppeString}.\n"
                . "• Gcinani imithi khatshana labantwana, omama abazithweleyo labamunyisayo, lezifuyo.\n"
                . "• Lingagezeli izifafazo eduzane lezibhorane, imifula kumbe amadamu; vikelani inyosi.\n"
                . "• Balani i-label yomuthi ngokucophelela lingakavubanisi.",
            LanguageCode::ENGLISH => "⚠️ *MANDATORY PPE & PESTICIDE SAFETY:*\n"
                . "• Wear full PPE: {$ppeString}.\n"
                . "• Keep chemicals locked away from children, pregnant or breastfeeding women, and livestock.\n"
                . "• Protect bees (do not spray during active bee foraging on blossoms) and water sources (never wash sprayers near boreholes, streams, or dams).\n"
                . "• Rotate IRAC/FRAC Mode of Action groups to prevent resistance. Always read and follow the Zimbabwe registered product label. " . $tenantCustomDisclaimer,
        };
    }
}
