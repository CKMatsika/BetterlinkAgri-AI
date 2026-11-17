<?php

declare(strict_types=1);

use App\DTOs\MatchedProductRecommendationData;
use App\Enums\ConversationState;
use App\Enums\DiagnosisCategory;
use App\Models\Conversation;
use App\Models\Crop;
use App\Models\Diagnosis;
use App\Models\Farmer;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\Diagnosis\AdvisoryGeneratorService;
use App\Services\Diagnosis\ProductMatcherService;
use App\Services\Diagnosis\SafetyGuardrailValidator;
use Database\Seeders\DatabaseSeeder;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('enforces guardrails: rejects invented doses, rejects unapproved tank mixes, and verifies SKUs against database', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263773100200', 'Farai Chikowore');
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::GUARDRAIL_VALIDATION,
    ]);

    $diagnosis = Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'farmer_id' => $farmer->id,
        'crop_id' => $maize->id,
        'category' => DiagnosisCategory::PEST,
        'final_diagnosis_name' => 'Fall Armyworm',
        'final_diagnosis_code' => 'fall_armyworm',
        'confidence' => 0.88,
    ]);

    $matcher = app(ProductMatcherService::class);
    $approvedProducts = $matcher->findApprovedProducts($tenant, 'fall_armyworm', $maize->id);

    expect($approvedProducts)->not->toBeEmpty();

    // 1. Valid deterministic product payload passes guardrails
    $validator = app(SafetyGuardrailValidator::class);
    $validCheck = $validator->validateAdvisoryPayload($tenant, $diagnosis, $approvedProducts, []);
    expect($validCheck->passed)->toBeTrue()
        ->and($validCheck->mandatorySafetyDisclaimer)->toContain('PPE')
        ->and($validCheck->mandatorySafetyDisclaimer)->toContain('bees')
        ->and($validCheck->mandatorySafetyDisclaimer)->toContain('children');

    // 2. Tampered / hallucinated dose (99.0g instead of 8.0g DB row) is caught and rejected
    $real = $approvedProducts[0];
    $hallucinatedDoseDto = new MatchedProductRecommendationData(
        productId: $real->productId,
        sku: $real->sku,
        productName: $real->productName,
        brandName: $real->brandName,
        activeIngredient: $real->activeIngredient,
        modeOfActionGroup: $real->modeOfActionGroup,
        registrationNumber: $real->registrationNumber,
        agronomicFitRankScore: $real->agronomicFitRankScore,
        dosePer16LKnapsackValue: 99.0, // HALLUCINATED DOSE!
        dosePer16LKnapsackUnit: $real->dosePer16LKnapsackUnit,
        dosePerHaValue: $real->dosePerHaValue,
        dosePerHaUnit: $real->dosePerHaUnit,
        waterVolumePerHaLitres: $real->waterVolumePerHaLitres,
        mixingOrder: $real->mixingOrder,
        applicationTimingNotes: $real->applicationTimingNotes,
        reapplicationIntervalDays: $real->reapplicationIntervalDays,
        phiDays: $real->phiDays,
        reiHours: $real->reiHours,
        packSizes: $real->packSizes,
        ppeRequirements: $real->ppeRequirements,
        safetyWarnings: $real->safetyWarnings,
        citedDocumentTitle: $real->citedDocumentTitle,
        citedPageNumber: $real->citedPageNumber,
    );

    $tamperedCheck = $validator->validateAdvisoryPayload($tenant, $diagnosis, [$hallucinatedDoseDto], []);
    expect($tamperedCheck->passed)->toBeFalse()
        ->and($tamperedCheck->violations[0])->toContain('Dose mismatch detected');

    // 3. Unapproved tank-mix instruction in text is caught and rejected
    $tankMixCheck = $validator->validateAdvisoryPayload(
        $tenant,
        $diagnosis,
        $approvedProducts,
        [],
        'You should tank-mix with Karate in one tank.'
    );
    expect($tankMixCheck->passed)->toBeFalse()
        ->and($tankMixCheck->violations[0])->toContain('Unapproved tank-mix');
});

it('escalates and gives only non-chemical advice when no registered product exists for the crop and target', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $farmer = Farmer::findOrCreateByPhone('+263773100201', 'Nyasha Mtetwa');
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    $conversation = Conversation::query()->create([
        'tenant_id' => $tenant->id,
        'farmer_id' => $farmer->id,
        'channel' => 'whatsapp',
        'state' => ConversationState::DETERMINISTIC_TOOL_EXECUTION,
    ]);

    $diagnosis = Diagnosis::query()->create([
        'tenant_id' => $tenant->id,
        'conversation_id' => $conversation->id,
        'farmer_id' => $farmer->id,
        'crop_id' => $maize->id,
        'category' => DiagnosisCategory::DISEASE,
        'final_diagnosis_name' => 'Maize Streak Virus (MSV)',
        'final_diagnosis_code' => 'maize_streak_virus_unregistered_target',
        'confidence' => 0.89,
    ]);

    $generator = app(AdvisoryGeneratorService::class);
    $advisory = $generator->generateGroundedAdvisory($tenant, $conversation, $diagnosis);

    expect($advisory->requiresEscalation)->toBeTrue()
        ->and($advisory->advisoryType)->toBe('non_chemical_escalation')
        ->and($advisory->products)->toBeEmpty()
        ->and($advisory->productOptionsSection)->toContain('No registered chemical product');
});

it('never recommends expired or restricted-use products', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    // Mark CoragenPro as restricted use
    Product::withoutGlobalScopes()
        ->where('sku', '=', 'ZIM-CHLORANT-200SC')
        ->update(['is_restricted_use' => true]);

    $matcher = app(ProductMatcherService::class);
    $matched = $matcher->findApprovedProducts($tenant, 'fall_armyworm', $maize->id);
    $skus = array_map(fn (MatchedProductRecommendationData $p) => $p->sku, $matched);

    expect($skus)->not->toContain('ZIM-CHLORANT-200SC')
        ->and($skus)->toContain('ZIM-EMAMEC-5SG');
});
