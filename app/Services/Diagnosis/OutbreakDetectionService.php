<?php

declare(strict_types=1);

namespace App\Services\Diagnosis;

use App\Models\Campaign;
use App\Models\Diagnosis;
use App\Models\OutbreakSignal;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;

/**
 * Section 9 & 14: Ward & District Outbreak Clustering Service.
 * Clusters confirmed / high-confidence (>= 0.70) diagnoses over a rolling 7-day window
 * and generates OutbreakSignal rows + draft outbreak_alert Campaigns.
 */
final class OutbreakDetectionService
{
    /**
     * Scan rolling 7-day diagnoses and return newly detected or updated OutbreakSignals.
     *
     * @return list<OutbreakSignal>
     */
    public function scanAndClusterOutbreaks(?string $tenantId = null): array
    {
        $threshold = (int) config('betterlink.diagnosis.outbreak_case_threshold', 5);
        $windowDays = (int) config('betterlink.diagnosis.outbreak_window_days', 7);
        $since = now()->subDays($windowDays);

        $query = Diagnosis::withoutGlobalScopes()
            ->select([
                'tenant_id',
                'ward_id',
                'crop_id',
                'final_diagnosis_code',
                DB::raw('COUNT(*) as cases_count'),
                DB::raw('AVG(confidence) as avg_conf'),
            ])
            ->whereNotNull('ward_id')
            ->whereNotNull('final_diagnosis_code')
            ->where('confidence', '>=', 0.70)
            ->where('created_at', '>=', $since)
            ->groupBy(['tenant_id', 'ward_id', 'crop_id', 'final_diagnosis_code'])
            ->havingRaw('COUNT(*) >= ?', [$threshold]);

        if ($tenantId !== null) {
            $query->where('tenant_id', '=', $tenantId);
        }

        $clusters = $query->get();
        $signals = [];

        foreach ($clusters as $cluster) {
            $ward = Ward::query()->find($cluster->ward_id);
            if ($ward === null) {
                continue;
            }

            $casesCount = (int) $cluster->cases_count;
            $severity = match (true) {
                $casesCount >= ($threshold * 2) => 'outbreak',
                $casesCount >= $threshold => 'warning',
                default => 'watch',
            };

            // Automatically draft a segmented outbreak alert campaign for the tenant
            $targetCode = (string) $cluster->final_diagnosis_code;
            $campaign = Campaign::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $cluster->tenant_id,
                    'name' => "Outbreak Alert: {$targetCode} in {$ward->name}",
                    'campaign_type' => 'outbreak_alert',
                ],
                [
                    'segment_filter_json' => [
                        'ward_ids' => [$ward->id],
                        'district_ids' => [$ward->district_id],
                        'crop_ids' => $cluster->crop_id ? [$cluster->crop_id] : [],
                    ],
                    'whatsapp_template_name' => 'zw_outbreak_warning_v1',
                    'whatsapp_template_status' => 'approved',
                    'body_en' => "🚨 OUTBREAK ALERT ({$ward->name}): {$casesCount} cases of {$targetCode} confirmed in your ward this week. Scout your crop today and send a photo for approved control options.",
                    'body_sn' => "🚨 CHENJEDZO ({$ward->name}): Dambudziko re {$targetCode} rawanda mudunhu renyu svondo rino. Ongororai munda wenyu nhasi mutumire mufananidzo.",
                    'body_nd' => "🚨 ISEXWAYISO ({$ward->name}): Uhlupho lwe {$targetCode} lubhahile esigabeni senu kuliviki. Hlolani insimu yenu lamuhla lithumele umfanekiso.",
                    'throttle_per_minute' => 60,
                    'status' => 'draft',
                ]
            );

            $signal = OutbreakSignal::query()->updateOrCreate(
                [
                    'tenant_id' => $cluster->tenant_id,
                    'ward_id' => $ward->id,
                    'crop_id' => $cluster->crop_id,
                    'target_code' => $targetCode,
                ],
                [
                    'district_id' => $ward->district_id,
                    'cases_count_7d' => $casesCount,
                    'avg_confidence' => round((float) $cluster->avg_conf, 4),
                    'severity' => $severity,
                    'alert_campaign_id' => $campaign->id,
                    'detected_at' => now(),
                ]
            );

            $signals[] = $signal;
        }

        return $signals;
    }
}
