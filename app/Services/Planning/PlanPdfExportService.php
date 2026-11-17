<?php

declare(strict_types=1);

namespace App\Services\Planning;

use App\Enums\LanguageCode;
use App\Models\FarmPlan;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Maintained DomPDF-backed summary exporter for Farm Plans (English, Shona, Ndebele).
 * Supports UTF-8 Unicode, automatic wrapping, and structured tables, while respecting
 * production unverified-benchmark redaction via PlanFinancialEngine::serializeForFarmer().
 */
final class PlanPdfExportService
{
    public function __construct(
        private readonly PlanFinancialEngine $financialEngine
    ) {
    }

    public function render(FarmPlan $plan, LanguageCode $language = LanguageCode::ENGLISH): string
    {
        $plan->loadMissing(['enterprise', 'projection', 'riskFlags', 'checklistItems', 'farmer']);
        $farmerPayload = $this->financialEngine->serializeForFarmer($plan, $plan->projection);

        return Pdf::loadView('planner.pdf', [
            'plan' => $plan,
            'language' => $language,
            'labels' => $this->labelsFor($language),
            'enterpriseName' => $this->localizedEnterpriseName($plan, $language),
            'farmerPayload' => $farmerPayload,
        ])->setPaper('a4', 'portrait')->output();
    }

    private function localizedEnterpriseName(FarmPlan $plan, LanguageCode $language): string
    {
        $enterprise = $plan->enterprise;
        if ($enterprise === null) {
            return 'Enterprise';
        }

        return match ($language) {
            LanguageCode::SHONA => $enterprise->name_sn ?: $enterprise->name_en,
            LanguageCode::NDEBELE => $enterprise->name_nd ?: $enterprise->name_en,
            default => $enterprise->name_en,
        };
    }

    /**
     * @return array<string, string>
     */
    private function labelsFor(LanguageCode $language): array
    {
        return match ($language) {
            LanguageCode::SHONA => [
                'title' => 'HURONGWA HWEKURIMA (FARM PLAN SUMMARY)',
                'plan' => 'Hurongwa',
                'enterprise' => 'Bhizinesi reKurima',
                'scale' => 'Hukuru',
                'status' => 'Mamiriro',
                'start_date' => 'Zuva reKutanga',
                'verdict' => 'ONGORORO YEKUBUDIRIRA',
                'financials' => 'MARI NEZVIRONGWA',
                'hidden_notice' => 'Hurongwa uhwu huri kuongororwa nenyanzvi (under agronomist review). Nhamba dzemari dzichaburitswa kana dzaongororwa.',
                'total_cost' => 'Mari yese inodiwa',
                'total_revenue' => 'Mari inotarisirwa kuuya',
                'net_margin' => 'Purofiti inosara',
                'peak_funding' => 'Mari yepamusoro inodiwa',
                'break_even' => 'Mutengo wekudzosa mari',
                'recommendations' => 'MAZANO NEZVINODIWA',
            ],
            LanguageCode::NDEBELE => [
                'title' => 'UHLELO LOKULIMA (FARM PLAN SUMMARY)',
                'plan' => 'Uhlelo',
                'enterprise' => 'Ibhizimusi Lokulima',
                'scale' => 'Ubukhulu',
                'status' => 'Isimo',
                'start_date' => 'Usuku Lokuqala',
                'verdict' => 'ISINQUMO SOKUPHUMELELA',
                'financials' => 'IZIMALI LEZINHLELO',
                'hidden_notice' => 'Loluhlelo luhlolwa ngabezolimo (under agronomist review). Izinombolo zezimali zizavela ngemva kokuhlolwa.',
                'total_cost' => 'Izindleko zonke',
                'total_revenue' => 'Imali elindelweyo',
                'net_margin' => 'Inzuzo esalayo',
                'peak_funding' => 'Imali ephezulu edingekayo',
                'break_even' => 'Intengo yokubuyisela izindleko',
                'recommendations' => 'IZELULEKO LEZOKULANDELA',
            ],
            default => [
                'title' => 'FARM PLAN SUMMARY',
                'plan' => 'Plan',
                'enterprise' => 'Enterprise',
                'scale' => 'Scale',
                'status' => 'Status',
                'start_date' => 'Planned Start Date',
                'verdict' => 'VIABILITY VERDICT',
                'financials' => 'FINANCIAL PROJECTIONS',
                'hidden_notice' => 'Under agronomist review. Financial figures are withheld until reference benchmarks are verified.',
                'total_cost' => 'Total Cost',
                'total_revenue' => 'Expected Revenue',
                'net_margin' => 'Net Margin',
                'peak_funding' => 'Peak Funding Need',
                'break_even' => 'Break-even Price',
                'recommendations' => 'RECOMMENDATIONS & CHECKLIST',
            ],
        };
    }
}
