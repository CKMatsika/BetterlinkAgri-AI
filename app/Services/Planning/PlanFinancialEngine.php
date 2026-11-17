<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Enums\CurrencyCode;
use App\Enums\PlanState;
use App\Enums\ViabilityVerdict;
use App\Models\Benchmark;
use App\Models\FarmPlan;
use App\Models\MarketPrice;
use App\Models\PlanAssumption;
use App\Models\PlanChecklistItem;
use App\Models\PlanProjection;
use App\Models\PlanReview;
use App\Models\PlanRiskFlag;
use App\Models\PlanScenario;
use Illuminate\Support\Facades\DB;

/**
 * Deterministic Financial & Viability Engine (PlanFinancialEngine).
 *
 * STRICT MATH INVARIANTS:
 * - ZERO PHP floats in financial math. All money values are integer minor units (cents).
 * - Uses bcmath + explicit HALF_UP rounding for division/percentage/FX operations.
 * - Explicit USD <-> ZiG conversion using exchange_rate_scaled (rate * 10000).
 * - Two explicit verdict labels:
 *   1. "demo data, needs agronomist review" -> ONLY when any unverified benchmark or market price is used;
 *      automatically creates a PlanReview entry in the Agronomist Review Queue.
 *   2. "based on your own figures" -> when farmer-supplied figures are used (and no unverified benchmarks);
 *      does NOT auto-route to the Agronomist Review Queue.
 */
final class PlanFinancialEngine
{
    public function __construct(
        private readonly PlanStateMachine $stateMachine
    ) {
    }

    /**
     * Compute full projections, 3 scenarios, sensitivity table, risk flags, checklist, and viability verdict.
     *
     * @param array<string, int> $farmerOverridesMinor Map of metric_key => integer minor units (or scaled * 100)
     */
    public function runProjections(FarmPlan $plan, array $farmerOverridesMinor = []): PlanProjection
    {
        return DB::transaction(function () use ($plan, $farmerOverridesMinor): PlanProjection {
            $plan->loadMissing(['enterprise', 'ward']);

            $enterpriseCode = (string) ($plan->enterprise?->code ?? 'broilers');
            $naturalRegion = (string) ($plan->ward?->natural_region?->value ?? 'ALL');
            $targetCurrency = $plan->currency ?? CurrencyCode::USD;
            $exchangeRateScaled = max(10000, (int) $plan->exchange_rate_scaled); // e.g. 255000 = 25.5000

            $scaleUnitsInt = max(1, (int) round((float) $plan->scale_value));

            $usedUnverifiedDemoData = false;
            $usedFarmerSuppliedFigures = $farmerOverridesMinor !== [];

            // Clear prior assumptions/scenarios/risks/checklist for clean deterministic recomputation
            $plan->assumptions()->delete();
            $plan->scenarios()->delete();
            $plan->riskFlags()->delete();
            $plan->checklistItems()->delete();

            if ($enterpriseCode === 'broilers') {
                $computed = $this->computeBroilerBudgetMinor(
                    $plan,
                    $scaleUnitsInt,
                    $naturalRegion,
                    $targetCurrency,
                    $exchangeRateScaled,
                    $farmerOverridesMinor,
                    $usedUnverifiedDemoData,
                    $usedFarmerSuppliedFigures
                );
            } else {
                $computed = $this->computeCropBudgetMinor(
                    $plan,
                    $enterpriseCode,
                    $scaleUnitsInt,
                    $naturalRegion,
                    $targetCurrency,
                    $exchangeRateScaled,
                    $farmerOverridesMinor,
                    $usedUnverifiedDemoData,
                    $usedFarmerSuppliedFigures
                );
            }

            $capitalMinor = $computed['capital_cost_minor'];
            $workingMinor = $computed['working_capital_minor'];
            $totalCostMinor = $capitalMinor + $workingMinor;
            $totalRevenueMinor = $computed['total_revenue_minor'];
            $grossMarginMinor = $totalRevenueMinor - $workingMinor;
            $netMarginMinor = $totalRevenueMinor - $totalCostMinor;

            // ROI scaled * 100 (e.g., 2540 = 25.40%)
            $roiScaled = $totalCostMinor > 0
                ? $this->mulDivRound($netMarginMinor, 10000, $totalCostMinor)
                : 0;

            // Scenarios: Conservative (-15% revenue, +10% cost), Base, Optimistic (+10% revenue, -5% cost)
            $consCost = $this->applyBasisPoints($totalCostMinor, 11000);
            $consRev = $this->applyBasisPoints($totalRevenueMinor, 8500);
            $consNet = $consRev - $consCost;

            $optCost = $this->applyBasisPoints($totalCostMinor, 9500);
            $optRev = $this->applyBasisPoints($totalRevenueMinor, 11000);
            $optNet = $optRev - $optCost;

            foreach ([
                ['conservative', $consCost, $consRev, $consNet],
                ['base', $totalCostMinor, $totalRevenueMinor, $netMarginMinor],
                ['optimistic', $optCost, $optRev, $optNet],
            ] as [$type, $sCost, $sRev, $sNet]) {
                PlanScenario::query()->create([
                    'farm_plan_id' => $plan->id,
                    'scenario_type' => $type,
                    'currency' => $targetCurrency->value,
                    'total_cost_minor' => $sCost,
                    'total_revenue_minor' => $sRev,
                    'net_margin_minor' => $sNet,
                    'roi_pct_scaled' => $sCost > 0 ? $this->mulDivRound($sNet, 10000, $sCost) : 0,
                ]);
            }

            // Sensitivity Table (exact integer minor units)
            $sensitivityTable = [
                'selling_price_minus_10_pct' => [
                    'label' => 'Selling Price -10%',
                    'revenue_minor' => $this->applyBasisPoints($totalRevenueMinor, 9000),
                    'net_margin_minor' => $this->applyBasisPoints($totalRevenueMinor, 9000) - $totalCostMinor,
                ],
                'selling_price_minus_20_pct' => [
                    'label' => 'Selling Price -20%',
                    'revenue_minor' => $this->applyBasisPoints($totalRevenueMinor, 8000),
                    'net_margin_minor' => $this->applyBasisPoints($totalRevenueMinor, 8000) - $totalCostMinor,
                ],
                'yield_or_mortality_worse_15_pct' => [
                    'label' => 'Yield / Survival -15%',
                    'revenue_minor' => $this->applyBasisPoints($totalRevenueMinor, 8500),
                    'net_margin_minor' => $this->applyBasisPoints($totalRevenueMinor, 8500) - $totalCostMinor,
                ],
                'input_costs_plus_15_pct' => [
                    'label' => 'Input Costs +15%',
                    'cost_minor' => $this->applyBasisPoints($totalCostMinor, 11500),
                    'net_margin_minor' => $totalRevenueMinor - $this->applyBasisPoints($totalCostMinor, 11500),
                ],
                'start_delay_1_month' => [
                    'label' => '1-Month Start Delay (Working Capital Holding +5%)',
                    'cost_minor' => $capitalMinor + $this->applyBasisPoints($workingMinor, 10500),
                    'net_margin_minor' => $totalRevenueMinor - ($capitalMinor + $this->applyBasisPoints($workingMinor, 10500)),
                ],
            ];

            $projection = PlanProjection::query()->updateOrCreate(
                ['farm_plan_id' => $plan->id],
                [
                    'currency' => $targetCurrency,
                    'capital_cost_minor' => $capitalMinor,
                    'working_capital_minor' => $workingMinor,
                    'total_cost_minor' => $totalCostMinor,
                    'total_revenue_minor' => $totalRevenueMinor,
                    'gross_margin_minor' => $grossMarginMinor,
                    'net_margin_minor' => $netMarginMinor,
                    'peak_funding_need_minor' => $totalCostMinor,
                    'break_even_price_minor' => $computed['break_even_price_minor'],
                    'break_even_volume_scaled' => $computed['break_even_volume_scaled'],
                    'payback_period_days' => $computed['payback_period_days'],
                    'roi_pct_scaled' => $roiScaled,
                    'monthly_cashflow_json' => $computed['monthly_cashflow'],
                    'cost_breakdown_json' => $computed['cost_breakdown'],
                    'sensitivity_table_json' => $sensitivityTable,
                ]
            );

            // Evaluate Viability Verdict & Two Explicit Labels
            [$verdict, $reasons, $conditions] = $this->evaluateVerdict($netMarginMinor, $consNet, $roiScaled, $plan);

            $allowUnverifiedDemo = (bool) config('betterlink.planning.allow_unverified_demo_numbers', false);
            $numbersHiddenPendingReview = false;

            if ($usedUnverifiedDemoData && !$allowUnverifiedDemo) {
                $verdictLabel = 'under agronomist review';
                $numbersHiddenPendingReview = true;
                $reasons = [
                    'This plan uses reference benchmarks or prices that are currently under agronomist review. Financial figures are withheld until verified.',
                ];
            } elseif ($usedUnverifiedDemoData) {
                $verdictLabel = 'demo data, needs agronomist review';
            } elseif ($usedFarmerSuppliedFigures) {
                $verdictLabel = 'based on your own figures';
            } else {
                $verdictLabel = 'verified';
            }

            $plan->update([
                'viability_verdict' => $verdict,
                'verdict_label' => $verdictLabel,
                'verdict_reasons_json' => $reasons,
                'verdict_conditions_json' => $conditions,
                'metadata_json' => array_merge($plan->metadata_json ?? [], [
                    'numbers_hidden_pending_review' => $numbersHiddenPendingReview,
                ]),
            ]);

            // Seed Risk Flags & Compliance Checklist
            $this->populateRiskAndChecklist($plan, $enterpriseCode, $naturalRegion);

            if ($plan->state === PlanState::PLAN_DRAFTED || $plan->state === PlanState::INTERVIEW) {
                $this->stateMachine->transitionTo($plan, PlanState::PROJECTION_READY, 'financial_projections_computed');
            }

            // ONLY route to Agronomist Review Queue when unverified demo benchmarks/prices were used
            if ($usedUnverifiedDemoData) {
                PlanReview::query()->firstOrCreate(
                    [
                        'farm_plan_id' => $plan->id,
                        'trigger_reason' => 'unverified_benchmarks',
                    ],
                    [
                        'status' => 'pending',
                    ]
                );
            }

            return $projection;
        });
    }

    /**
     * Exact integer-minor-unit money conversion from USD cents to target currency cents (HALF_UP).
     */
    public function convertUsdMinorToCurrency(int $usdMinor, CurrencyCode $targetCurrency, int $exchangeRateScaled): int
    {
        if ($targetCurrency === CurrencyCode::USD) {
            return $usdMinor;
        }

        // exchangeRateScaled = rate * 10000 (e.g. 25.5000 => 255000)
        return $this->mulDivRound($usdMinor, $exchangeRateScaled, 10000);
    }

    /**
     * Compute ($value * $numerator) / $denominator using bcmath with HALF_UP rounding.
     */
    public function mulDivRound(int $value, int $numerator, int $denominator): int
    {
        if ($denominator === 0) {
            return 0;
        }

        $product = bcmul((string) $value, (string) $numerator, 0);
        $quotient = bcdiv($product, (string) $denominator, 4);

        // HALF_UP rounding to nearest integer
        $isNegative = str_starts_with($quotient, '-');
        $absQuotient = ltrim($quotient, '-');
        [$intPart, $fracPart] = array_pad(explode('.', $absQuotient, 2), 2, '0');
        $firstDigit = (int) substr($fracPart, 0, 1);

        $rounded = (int) $intPart;
        if ($firstDigit >= 5) {
            $rounded++;
        }

        return $isNegative ? -$rounded : $rounded;
    }

    /**
     * Apply basis points (10000 = 100.00%, 8500 = 85.00%, 11500 = 115.00%).
     */
    private function applyBasisPoints(int $amountMinor, int $basisPoints): int
    {
        return $this->mulDivRound($amountMinor, $basisPoints, 10000);
    }

    /**
     * @param array<string, int> $overrides
     * @return array<string, mixed>
     */
    private function computeBroilerBudgetMinor(
        FarmPlan $plan,
        int $birdsCount,
        string $naturalRegion,
        CurrencyCode $currency,
        int $exchangeRateScaled,
        array $overrides,
        bool &$usedUnverifiedDemoData,
        bool &$usedFarmerSuppliedFigures
    ): array {
        $mortalityScaled = $this->resolveMetric($plan, 'broilers', $naturalRegion, 'mortality_pct', 'Mortality Rate (%)', '%', 500, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);
        $chickCostMinor = $this->resolveMoneyMetric($plan, 'broilers', $naturalRegion, 'day_old_chick_cost_minor', 'Day-Old Chick Unit Cost', 'bird', 110, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);
        $feedCostPerBirdMinor = $this->resolveMoneyMetric($plan, 'broilers', $naturalRegion, 'feed_cost_per_bird_minor', 'Total Feed Cost per Bird', 'bird', 340, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);
        $vetMiscPerBirdMinor = $this->resolveMoneyMetric($plan, 'broilers', $naturalRegion, 'vet_brooding_per_bird_minor', 'Vaccines, Bedding & Heating per Bird', 'bird', 65, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);
        $housingCapexMinor = $this->resolveMoneyMetric($plan, 'broilers', $naturalRegion, 'housing_equip_capex_minor', 'Broiler Housing & Drinkers/Feeders Setup', 'batch', 50000, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);

        $sellingPricePerBirdMinor = $this->resolveMarketPriceMinor($plan, 'live_broiler', 'Live Broiler Bird Selling Price', 'bird', 650, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);

        $chicksTotalMinor = $birdsCount * $chickCostMinor;
        $feedTotalMinor = $birdsCount * $feedCostPerBirdMinor;
        $vetTotalMinor = $birdsCount * $vetMiscPerBirdMinor;
        $workingCapitalMinor = $chicksTotalMinor + $feedTotalMinor + $vetTotalMinor;

        // Surviving birds = birdsCount * (10000 - mortalityScaled) / 10000
        $survivalBasisPoints = max(0, 10000 - $mortalityScaled);
        $survivingBirds = $this->mulDivRound($birdsCount, $survivalBasisPoints, 10000);
        $totalRevenueMinor = $survivingBirds * $sellingPricePerBirdMinor;

        $totalCostMinor = $housingCapexMinor + $workingCapitalMinor;
        $breakEvenPriceMinor = $survivingBirds > 0 ? $this->mulDivRound($totalCostMinor, 1, $survivingBirds) : 0;
        $breakEvenBirds = $sellingPricePerBirdMinor > 0 ? $this->mulDivRound($totalCostMinor, 100, $sellingPricePerBirdMinor) : 0;

        return [
            'capital_cost_minor' => $housingCapexMinor,
            'working_capital_minor' => $workingCapitalMinor,
            'total_revenue_minor' => $totalRevenueMinor,
            'break_even_price_minor' => $breakEvenPriceMinor,
            'break_even_volume_scaled' => $breakEvenBirds,
            'payback_period_days' => 42,
            'cost_breakdown' => [
                ['label' => 'Day-old chicks', 'amount_minor' => $chicksTotalMinor],
                ['label' => 'Starter, Grower & Finisher Feed', 'amount_minor' => $feedTotalMinor],
                ['label' => 'Vaccines, Brooding & Litter', 'amount_minor' => $vetTotalMinor],
                ['label' => 'Housing & Equipment (CAPEX)', 'amount_minor' => $housingCapexMinor],
            ],
            'monthly_cashflow' => [
                ['month' => 1, 'inflow_minor' => 0, 'outflow_minor' => $housingCapexMinor + $chicksTotalMinor + $this->applyBasisPoints($feedTotalMinor, 6000), 'net_minor' => -($housingCapexMinor + $chicksTotalMinor + $this->applyBasisPoints($feedTotalMinor, 6000))],
                ['month' => 2, 'inflow_minor' => $totalRevenueMinor, 'outflow_minor' => $vetTotalMinor + $this->applyBasisPoints($feedTotalMinor, 4000), 'net_minor' => $totalRevenueMinor - ($vetTotalMinor + $this->applyBasisPoints($feedTotalMinor, 4000))],
            ],
        ];
    }

    /**
     * @param array<string, int> $overrides
     * @return array<string, mixed>
     */
    private function computeCropBudgetMinor(
        FarmPlan $plan,
        string $enterpriseCode,
        int $hectaresInt,
        string $naturalRegion,
        CurrencyCode $currency,
        int $exchangeRateScaled,
        array $overrides,
        bool &$usedUnverifiedDemoData,
        bool &$usedFarmerSuppliedFigures
    ): array {
        $yieldTonnesPerHaScaled = $this->resolveMetric($plan, $enterpriseCode, $naturalRegion, 'yield_tonnes_per_ha_scaled', 'Expected Yield (t/ha * 100)', 't/ha', 450, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);
        $seedFertChemPerHaMinor = $this->resolveMoneyMetric($plan, $enterpriseCode, $naturalRegion, 'inputs_cost_per_ha_minor', 'Seed, Fertiliser & Crop Protection per Ha', 'ha', 65000, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);
        $labourTillagePerHaMinor = $this->resolveMoneyMetric($plan, $enterpriseCode, $naturalRegion, 'labour_tillage_per_ha_minor', 'Land Prep, Labour & Harvesting per Ha', 'ha', 32000, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);
        $pricePerTonneMinor = $this->resolveMarketPriceMinor($plan, "{$enterpriseCode}_tonne", ucfirst($enterpriseCode) . ' Grain Price per Tonne', 'tonne', 35000, $currency, $exchangeRateScaled, $overrides, $usedUnverifiedDemoData, $usedFarmerSuppliedFigures);

        $workingCapitalMinor = $hectaresInt * ($seedFertChemPerHaMinor + $labourTillagePerHaMinor);
        $totalTonnesScaled = $hectaresInt * $yieldTonnesPerHaScaled; // * 100
        $totalRevenueMinor = $this->mulDivRound($totalTonnesScaled, $pricePerTonneMinor, 100);

        $breakEvenPriceMinor = $totalTonnesScaled > 0 ? $this->mulDivRound($workingCapitalMinor, 100, $totalTonnesScaled) : 0;
        $breakEvenTonnesScaled = $pricePerTonneMinor > 0 ? $this->mulDivRound($workingCapitalMinor, 100, $pricePerTonneMinor) : 0;

        return [
            'capital_cost_minor' => 0,
            'working_capital_minor' => $workingCapitalMinor,
            'total_revenue_minor' => $totalRevenueMinor,
            'break_even_price_minor' => $breakEvenPriceMinor,
            'break_even_volume_scaled' => $breakEvenTonnesScaled,
            'payback_period_days' => 150,
            'cost_breakdown' => [
                ['label' => 'Seed, Basal, AN & Chemicals', 'amount_minor' => $hectaresInt * $seedFertChemPerHaMinor],
                ['label' => 'Tillage, Weeding & Harvest Labour', 'amount_minor' => $hectaresInt * $labourTillagePerHaMinor],
            ],
            'monthly_cashflow' => [
                ['month' => 1, 'inflow_minor' => 0, 'outflow_minor' => $this->applyBasisPoints($workingCapitalMinor, 5500), 'net_minor' => -$this->applyBasisPoints($workingCapitalMinor, 5500)],
                ['month' => 3, 'inflow_minor' => 0, 'outflow_minor' => $this->applyBasisPoints($workingCapitalMinor, 2500), 'net_minor' => -$this->applyBasisPoints($workingCapitalMinor, 2500)],
                ['month' => 5, 'inflow_minor' => $totalRevenueMinor, 'outflow_minor' => $this->applyBasisPoints($workingCapitalMinor, 2000), 'net_minor' => $totalRevenueMinor - $this->applyBasisPoints($workingCapitalMinor, 2000)],
            ],
        ];
    }

    /**
     * @param array<string, int> $overrides
     */
    private function resolveMetric(
        FarmPlan $plan,
        string $enterpriseCode,
        string $naturalRegion,
        string $metricKey,
        string $label,
        string $unit,
        int $fallbackUnverifiedValue,
        array $overrides,
        bool &$usedUnverifiedDemoData,
        bool &$usedFarmerSuppliedFigures
    ): int {
        if (isset($overrides[$metricKey])) {
            $usedFarmerSuppliedFigures = true;
            $val = (int) $overrides[$metricKey];
            PlanAssumption::query()->create([
                'farm_plan_id' => $plan->id,
                'assumption_key' => $metricKey,
                'label' => $label,
                'value_scaled' => $val,
                'unit' => $unit,
                'source_type' => 'farmer_override',
                'source_citation' => 'Farmer-supplied figure',
                'status' => 'farmer_supplied',
            ]);

            return $val;
        }

        $bench = Benchmark::query()
            ->where('enterprise_code', '=', $enterpriseCode)
            ->where('metric_key', '=', $metricKey)
            ->whereIn('natural_region', [$naturalRegion, 'ALL'])
            ->orderByRaw("CASE WHEN status = 'verified' THEN 0 ELSE 1 END")
            ->first();

        if ($bench === null || $bench->status !== 'verified') {
            $usedUnverifiedDemoData = true;
        }

        $val = $bench !== null ? (int) $bench->value_scaled : $fallbackUnverifiedValue;
        PlanAssumption::query()->create([
            'farm_plan_id' => $plan->id,
            'assumption_key' => $metricKey,
            'label' => $label,
            'value_scaled' => $val,
            'unit' => $unit,
            'source_type' => 'benchmark',
            'reference_id' => $bench?->id,
            'source_citation' => $bench?->source?->title ?? 'Unverified reference placeholder',
            'status' => $bench?->status ?? 'unverified',
        ]);

        return $val;
    }

    /**
     * @param array<string, int> $overrides
     */
    private function resolveMoneyMetric(
        FarmPlan $plan,
        string $enterpriseCode,
        string $naturalRegion,
        string $metricKey,
        string $label,
        string $unit,
        int $fallbackUsdMinor,
        CurrencyCode $targetCurrency,
        int $exchangeRateScaled,
        array $overrides,
        bool &$usedUnverifiedDemoData,
        bool &$usedFarmerSuppliedFigures
    ): int {
        if (isset($overrides[$metricKey])) {
            $usedFarmerSuppliedFigures = true;
            $val = (int) $overrides[$metricKey];
            PlanAssumption::query()->create([
                'farm_plan_id' => $plan->id,
                'assumption_key' => $metricKey,
                'label' => $label,
                'value_scaled' => $val,
                'unit' => $unit,
                'currency' => $targetCurrency->value,
                'source_type' => 'farmer_override',
                'source_citation' => 'Farmer-supplied figure',
                'status' => 'farmer_supplied',
            ]);

            return $val;
        }

        $bench = Benchmark::query()
            ->where('enterprise_code', '=', $enterpriseCode)
            ->where('metric_key', '=', $metricKey)
            ->whereIn('natural_region', [$naturalRegion, 'ALL'])
            ->orderByRaw("CASE WHEN status = 'verified' THEN 0 ELSE 1 END")
            ->first();

        if ($bench === null || $bench->status !== 'verified') {
            $usedUnverifiedDemoData = true;
        }

        $usdMinor = $bench !== null ? (int) $bench->value_scaled : $fallbackUsdMinor;
        $converted = $this->convertUsdMinorToCurrency($usdMinor, $targetCurrency, $exchangeRateScaled);

        PlanAssumption::query()->create([
            'farm_plan_id' => $plan->id,
            'assumption_key' => $metricKey,
            'label' => $label,
            'value_scaled' => $converted,
            'unit' => $unit,
            'currency' => $targetCurrency->value,
            'source_type' => 'benchmark',
            'reference_id' => $bench?->id,
            'source_citation' => $bench?->source?->title ?? 'Unverified reference placeholder',
            'status' => $bench?->status ?? 'unverified',
        ]);

        return $converted;
    }

    /**
     * @param array<string, int> $overrides
     */
    private function resolveMarketPriceMinor(
        FarmPlan $plan,
        string $commodityCode,
        string $label,
        string $unit,
        int $fallbackUsdMinor,
        CurrencyCode $targetCurrency,
        int $exchangeRateScaled,
        array $overrides,
        bool &$usedUnverifiedDemoData,
        bool &$usedFarmerSuppliedFigures
    ): int {
        $overrideKey = "market_price_{$commodityCode}";
        if (isset($overrides[$overrideKey])) {
            $usedFarmerSuppliedFigures = true;
            $val = (int) $overrides[$overrideKey];
            PlanAssumption::query()->create([
                'farm_plan_id' => $plan->id,
                'assumption_key' => $overrideKey,
                'label' => $label,
                'value_scaled' => $val,
                'unit' => $unit,
                'currency' => $targetCurrency->value,
                'source_type' => 'farmer_override',
                'source_citation' => 'Farmer-supplied selling price',
                'status' => 'farmer_supplied',
            ]);

            return $val;
        }

        $marketPrice = MarketPrice::query()
            ->where('commodity_code', '=', $commodityCode)
            ->when(
                $plan->tenant_id !== null,
                fn ($q) => $q->where(fn ($sub) => $sub->where('tenant_id', '=', $plan->tenant_id)->orWhereNull('tenant_id')),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->orderByRaw('CASE WHEN tenant_id IS NOT NULL THEN 0 ELSE 1 END')
            ->orderByDesc('effective_date')
            ->first();

        if ($marketPrice === null || $marketPrice->status !== 'verified') {
            $usedUnverifiedDemoData = true;
        }

        $rawMinor = $marketPrice !== null ? (int) $marketPrice->price_minor : $fallbackUsdMinor;
        $rawCurrency = $marketPrice?->currency ?? CurrencyCode::USD;

        $finalMinor = $rawCurrency === $targetCurrency
            ? $rawMinor
            : $this->convertUsdMinorToCurrency($rawMinor, $targetCurrency, $exchangeRateScaled);

        PlanAssumption::query()->create([
            'farm_plan_id' => $plan->id,
            'assumption_key' => $overrideKey,
            'label' => $label,
            'value_scaled' => $finalMinor,
            'unit' => $unit,
            'currency' => $targetCurrency->value,
            'source_type' => 'market_price',
            'reference_id' => $marketPrice?->id,
            'source_citation' => $marketPrice?->source ?? 'Unverified market price placeholder',
            'status' => $marketPrice?->status ?? 'unverified',
        ]);

        return $finalMinor;
    }

    /**
     * @return array{0: ViabilityVerdict, 1: list<string>, 2: list<string>}
     */
    private function evaluateVerdict(
        int $baseNetMarginMinor,
        int $conservativeNetMarginMinor,
        int $roiScaled,
        FarmPlan $plan
    ): array {
        $flaggedAnswersCount = $plan->interviewAnswers()->where('is_flagged_assumption', '=', true)->count();
        $reasons = [];
        $conditions = [];

        if ($baseNetMarginMinor <= 0) {
            $reasons[] = 'Base-case net margin is negative or zero under current cost and price assumptions.';
            $conditions[] = 'Reduce initial capital cost, verify lower-cost input quotes, or start at a smaller staged scale.';

            return [ViabilityVerdict::NOT_VIABLE, $reasons, $conditions];
        }

        if ($conservativeNetMarginMinor < 0 || $flaggedAnswersCount > 0) {
            $reasons[] = 'Base-case margin is positive, but conservative scenario (-15% revenue / +10% cost) turns negative or key assumptions are flagged.';
            if ($flaggedAnswersCount > 0) {
                $conditions[] = 'Resolve flagged operational assumptions (e.g. irrigation availability or biosecurity downtime).';
            }
            if ($conservativeNetMarginMinor < 0) {
                $conditions[] = 'Lock in an offtake price or reduce working capital cost before committing.';
            }

            return [ViabilityVerdict::VIABLE_WITH_CONDITIONS, $reasons, $conditions];
        }

        if ($roiScaled < 1200) {
            $reasons[] = 'Both base and conservative cases are positive, but projected ROI is below 12%.';
            $conditions[] = 'Improve feed conversion / yield per hectare or target higher-margin market channels.';

            return [ViabilityVerdict::MARGINAL, $reasons, $conditions];
        }

        $reasons[] = 'Both base and conservative scenarios produce positive net margins with ROI >= 12%.';

        return [ViabilityVerdict::VIABLE, $reasons, $conditions];
    }

    private function populateRiskAndChecklist(FarmPlan $plan, string $enterpriseCode, string $naturalRegion): void
    {
        PlanRiskFlag::query()->create([
            'farm_plan_id' => $plan->id,
            'risk_category' => 'market',
            'severity' => 'medium',
            'description' => 'Output selling prices fluctuate seasonally; projections are indicative ranges, not guaranteed.',
            'mitigation_note' => 'Secure advance offtake commitments or stagger batch timing.',
        ]);

        if ($enterpriseCode === 'broilers') {
            PlanRiskFlag::query()->create([
                'farm_plan_id' => $plan->id,
                'risk_category' => 'disease',
                'severity' => 'high',
                'description' => 'Newcastle Disease, Gumboro, and brooding heat loss can exceed 5% mortality without strict biosecurity.',
                'mitigation_note' => 'Follow Day 1-21 vaccination schedule and maintain 14-day rest period between batches.',
            ]);

            PlanChecklistItem::query()->create([
                'farm_plan_id' => $plan->id,
                'title' => 'Department of Veterinary Services / Local Council Biosecurity & Abattoir Compliance Check',
                'authority_or_body' => 'DVS / Local Authority',
                'description' => 'Verify local municipal/rural council poultry housing bylaws and veterinary movement permits if selling dressed birds.',
            ]);
        } else {
            PlanRiskFlag::query()->create([
                'farm_plan_id' => $plan->id,
                'risk_category' => 'weather_region',
                'severity' => in_array($naturalRegion, ['IV', 'V'], true) ? 'high' : 'medium',
                'description' => "Mid-season dry spells in Natural Region {$naturalRegion} can reduce rainfed yields.",
                'mitigation_note' => 'Plant drought-tolerant maturity hybrids, mulch Pfumvudza basins, and split Nitrogen top-dressing.',
            ]);

            PlanChecklistItem::query()->create([
                'farm_plan_id' => $plan->id,
                'title' => 'Agritex / GMB / Merchant Registration & Soil pH Test Check',
                'authority_or_body' => 'Agritex / GMB',
                'description' => 'Check soil pH (<5.2 CaCl2 requires lime) and confirm grain delivery grade standards with GMB or buyer.',
            ]);
        }
    }

    /**
     * Format projection & verdict for farmer-facing API/UI/PDF, hiding numbers when
     * unverified benchmarks are withheld in production ('under agronomist review').
     *
     * @return array<string, mixed>
     */
    public function serializeForFarmer(FarmPlan $plan, ?PlanProjection $projection = null): array
    {
        $projection ??= $plan->projection;
        $hidden = (bool) ($plan->metadata_json['numbers_hidden_pending_review'] ?? false);

        if ($hidden || $projection === null) {
            return [
                'numbers_hidden_pending_review' => $hidden,
                'viability_verdict' => $hidden ? 'under_agronomist_review' : ($plan->viability_verdict?->value ?? $plan->viability_verdict),
                'verdict_label' => $plan->verdict_label ?? ($hidden ? 'under agronomist review' : null),
                'verdict_reasons' => $plan->verdict_reasons_json ?? [],
                'verdict_conditions' => $plan->verdict_conditions_json ?? [],
                'projection' => null,
            ];
        }

        return [
            'numbers_hidden_pending_review' => false,
            'viability_verdict' => $plan->viability_verdict?->value ?? $plan->viability_verdict,
            'verdict_label' => $plan->verdict_label,
            'verdict_reasons' => $plan->verdict_reasons_json ?? [],
            'verdict_conditions' => $plan->verdict_conditions_json ?? [],
            'projection' => [
                'currency' => $projection->currency,
                'capital_cost_minor' => $projection->capital_cost_minor,
                'working_capital_minor' => $projection->working_capital_minor,
                'total_cost_minor' => $projection->total_cost_minor,
                'total_revenue_minor' => $projection->total_revenue_minor,
                'gross_margin_minor' => $projection->gross_margin_minor,
                'net_margin_minor' => $projection->net_margin_minor,
                'peak_funding_need_minor' => $projection->peak_funding_need_minor,
                'break_even_price_minor' => $projection->break_even_price_minor,
                'break_even_volume_scaled' => $projection->break_even_volume_scaled,
                'payback_period_days' => $projection->payback_period_days,
                'roi_pct_scaled' => $projection->roi_pct_scaled,
                'monthly_cashflow' => $projection->monthly_cashflow_json ?? [],
                'cost_breakdown' => $projection->cost_breakdown_json ?? [],
                'sensitivity_table' => $projection->sensitivity_table_json ?? [],
            ],
        ];
    }
}
