<?php

declare(strict_types=1);

use App\DTOs\MatchedProductRecommendationData;
use App\Models\Crop;
use App\Models\Tenant;
use App\Services\Diagnosis\ApplicationRateCalculator;
use Database\Seeders\DatabaseSeeder;

beforeEach(function (): void {
    $this->seed(DatabaseSeeder::class);
});

it('calculates fertiliser application rates three ways: per plant, per Pfumvudza station, and per hectare with 50kg bags', function (): void {
    $tenant = Tenant::query()->where('slug', '=', 'farmserve-zimbabwe')->firstOrFail();
    $maize = Crop::query()->where('code', '=', 'maize')->firstOrFail();

    $calculator = app(ApplicationRateCalculator::class);
    $result = $calculator->calculateFertiliserRate(
        tenant: $tenant,
        crop: $maize,
        nutrientAddressed: 'N',
        fieldSizeHa: 0.50
    );

    expect($result)->not->toBeNull()
        ->and($result->productOrFertiliserName)->toContain('Ammonium Nitrate')
        ->and($result->rateGramsPerPlant)->toBe(5.00)
        ->and($result->localMeasurePerPlant)->toContain('Nr. 5 cup')
        ->and($result->rateGramsPerPfumvudzaStation)->toBe(10.00)
        ->and($result->totalKgPerPfumvudzaPlot)->toBe(14.56) // 1,456 stations * 10g = 14.56 kg
        ->and($result->rateKgPerHa)->toBe(250.00)
        ->and($result->bags50KgPerHa)->toBe(5.00)
        ->and($result->totalKgForField)->toBe(125.00) // 0.5 ha * 250 kg/ha
        ->and($result->bags50KgForField)->toBe(2.50)
        ->and($result->workingSteps)->toHaveCount(3);
});

it('calculates chemical spray rates per 16L knapsack and per hectare for the farmer field size', function (): void {
    $calculator = app(ApplicationRateCalculator::class);

    $productDto = new MatchedProductRecommendationData(
        productId: 'prod-1',
        sku: 'ZIM-EMAMEC-5SG',
        productName: 'BeltGuard Emamectin 5% SG',
        brandName: 'ZimCrop',
        activeIngredient: 'Emamectin benzoate 50 g/kg SG',
        modeOfActionGroup: 'IRAC Group 6',
        registrationNumber: 'ZW-PPRI-2023-0412',
        agronomicFitRankScore: 95.0,
        dosePer16LKnapsackValue: 8.0,
        dosePer16LKnapsackUnit: 'g',
        dosePerHaValue: 150.0,
        dosePerHaUnit: 'g',
        waterVolumePerHaLitres: 250.0,
        mixingOrder: 'Half-fill tank, add 8g, top up to 16L.',
        applicationTimingNotes: 'Apply into whorl.',
        reapplicationIntervalDays: 10,
        phiDays: 14,
        reiHours: 12,
        packSizes: [['size' => '100g', 'price_usd' => 6.50, 'price_zig' => 165.75]],
        ppeRequirements: ['gloves', 'mask'],
        safetyWarnings: ['bee_warning' => 'Toxic to bees'],
        citedDocumentTitle: 'Label',
        citedPageNumber: 12
    );

    $sprayRate = $calculator->calculateSprayChemicalRate($productDto, 2.0);

    expect($sprayRate->dosePer16LKnapsackValue)->toBe(8.0)
        ->and($sprayRate->dosePer16LKnapsackUnit)->toBe('g')
        ->and($sprayRate->dosePerHaValue)->toBe(150.0)
        ->and($sprayRate->knapsacksPerHa)->toBe(16) // ceil(250 / 16)
        ->and($sprayRate->knapsacksForField)->toBe(32)
        ->and($sprayRate->totalProductForFieldValue)->toBe(300.0);
});
