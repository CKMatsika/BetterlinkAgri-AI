<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Components;

use App\DTOs\MatchedProductRecommendationData;
use App\Models\Conversation;
use App\Models\Product;
use App\Services\Diagnosis\ProductMatcherService;

/**
 * Section 10: Connected Farmer Profile Panel Service / Component Builder.
 *
 * Renders beside every conversation in Filament and the Rep App:
 * - Farmer ID, Name, Phone, Language, Farming Sector, Pfumvudza status
 * - Crop and variety, Location (Ward & Natural Region), Last active timestamp
 * - Growth stage, Area under crop (ha), Recent diagnostic challenge & confidence
 * - "Approved portfolio for this context" with "Show all approved options"
 */
final class ConnectedFarmerProfilePanel
{
    public function __construct(
        private readonly ProductMatcherService $productMatcher
    ) {
    }

    /**
     * Build the live-updating profile and approved portfolio context payload for a Conversation.
     *
     * @return array{
     *   farmer_id: string,
     *   farmer_short_id: string,
     *   full_name: string,
     *   phone: string,
     *   preferred_language: string,
     *   farming_sector: string,
     *   is_pfumvudza_enrolled: bool,
     *   context_chip: string,
     *   crop_and_variety: string,
     *   location_summary: string,
     *   natural_region: string,
     *   last_active_human: string,
     *   growth_stage: string,
     *   area_under_crop_ha: string,
     *   recent_challenge: string,
     *   recent_confidence_pct: ?int,
     *   approved_portfolio_for_context: list<array<string, mixed>>,
     *   all_approved_options: list<array<string, mixed>>
     * }
     */
    public function buildForConversation(Conversation $conversation): array
    {
        $conversation->loadMissing(['farmer.ward.district.province', 'farmer.farms', 'tenant']);
        $farmer = $conversation->farmer;
        $tenant = $conversation->tenant;
        $farm = $farmer?->farms->first();
        $latestDiagnosis = $conversation->diagnoses()->with(['crop', 'livestock', 'ward'])->latest()->first();

        $ward = $latestDiagnosis?->ward ?? $farmer?->ward ?? $farm?->ward;
        $naturalRegion = $ward?->natural_region?->value ?? $farm?->natural_region?->value ?? 'IIa';
        $wardLabel = $ward !== null
            ? "{$ward->name} ({$ward->district?->name})"
            : 'Ward not yet set';

        $cropName = $latestDiagnosis?->crop?->name_en
            ?? $latestDiagnosis?->livestock?->name_en
            ?? (is_array($farm?->crops_grown_json) && $farm->crops_grown_json !== [] ? implode(', ', $farm->crops_grown_json) : 'Maize');

        $growthStage = $latestDiagnosis?->growth_stage ?? 'Vegetative (V4–V6)';
        $areaHa = $latestDiagnosis?->field_size_ha ?? $farm?->cultivated_area_ha;
        $areaDisplay = $areaHa !== null ? number_format((float) $areaHa, 2) . ' ha' : 'Pending';
        if ($latestDiagnosis?->pfumvudza_plots_count) {
            $areaDisplay .= " ({$latestDiagnosis->pfumvudza_plots_count} Pfumvudza plots)";
        }

        $recentChallenge = $latestDiagnosis?->final_diagnosis_name ?? 'No active diagnosis';
        $confidencePct = $latestDiagnosis !== null
            ? (int) round(((float) $latestDiagnosis->confidence) * 100)
            : null;

        // Context-matched approved portfolio
        $contextPortfolio = [];
        if ($tenant !== null && $latestDiagnosis?->final_diagnosis_code !== null) {
            $matched = $this->productMatcher->findApprovedProducts(
                tenant: $tenant,
                targetCode: (string) $latestDiagnosis->final_diagnosis_code,
                cropId: $latestDiagnosis->crop_id ? (string) $latestDiagnosis->crop_id : null,
                livestockId: $latestDiagnosis->livestock_id ? (string) $latestDiagnosis->livestock_id : null,
                limit: 5
            );
            $contextPortfolio = array_map(fn (MatchedProductRecommendationData $p) => $p->toArray(), $matched);
        }

        // All approved options in the tenant's catalogue ("Show all approved options")
        $allApproved = [];
        if ($tenant !== null) {
            $allApproved = Product::withoutGlobalScopes()
                ->where('tenant_id', '=', $tenant->id)
                ->where('is_active', '=', true)
                ->where('is_restricted_use', '=', false)
                ->orderByDesc('agronomic_fit_rank_score')
                ->get()
                ->map(fn (Product $p) => [
                    'product_id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category' => $p->category,
                    'active_ingredient' => $p->active_ingredient,
                    'mode_of_action_group' => $p->mode_of_action_group,
                    'registration_number' => $p->registration_number,
                    'pack_sizes' => $p->pack_sizes_json,
                ])
                ->all();
        }

        return [
            'farmer_id' => (string) ($farmer?->id ?? ''),
            'farmer_short_id' => 'FRM-' . strtoupper(substr((string) ($farmer?->id ?? '00000000'), 0, 8)),
            'full_name' => (string) ($farmer?->full_name_encrypted ?: 'Unregistered Name'),
            'phone' => (string) ($farmer?->phone_encrypted ?: $conversation->external_thread_id ?: ''),
            'preferred_language' => $conversation->language->label(),
            'farming_sector' => $farmer?->farming_sector?->label() ?? 'Communal / A1',
            'is_pfumvudza_enrolled' => (bool) ($farmer?->is_pfumvudza_enrolled ?? false),
            'context_chip' => (string) ($conversation->context_chip ?: "{$cropName} / {$growthStage} / NR {$naturalRegion}"),
            'crop_and_variety' => $cropName,
            'location_summary' => $wardLabel,
            'natural_region' => "Natural Region {$naturalRegion}",
            'last_active_human' => $farmer?->last_active_at?->diffForHumans() ?? 'Just now',
            'growth_stage' => $growthStage,
            'area_under_crop_ha' => $areaDisplay,
            'recent_challenge' => $recentChallenge,
            'recent_confidence_pct' => $confidencePct,
            'approved_portfolio_for_context' => $contextPortfolio,
            'all_approved_options' => $allApproved,
        ];
    }
}
