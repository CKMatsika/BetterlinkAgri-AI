<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Models\Tenant;
use App\Services\Analytics\TenantAnalyticsService;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Section 14: Primary Tenant Analytics KPI Overview Widget.
 */
final class OverviewKpiStatsWidget extends BaseWidget
{
    protected function getStats(): array
    {
        /** @var Tenant|null $tenant */
        $tenant = Filament::getTenant() ?? Tenant::query()->first();
        if ($tenant === null) {
            return [];
        }

        $m = app(TenantAnalyticsService::class)->computeDashboardMetrics($tenant->id);

        return [
            Stat::make('Farmers Reached & Conversations', (string) $m['farmers_reached'])
                ->description("{$m['total_conversations']} conversations • {$m['total_diagnoses']} photo/symptom diagnoses")
                ->color('success'),

            Stat::make('New Sales First Engaged via AI', 'US$' . number_format((float) $m['new_sales_first_engaged_ai_usd'], 2))
                ->description('Upsell Uplift on Engaged Farmers: US$' . number_format((float) $m['upsell_uplift_engaged_farmers_usd'], 2))
                ->color('primary'),

            Stat::make('AI Diagnosis Accuracy', "{$m['ai_accuracy_pct']}%")
                ->description("Verified across {$m['reviewed_diagnoses_count']} Agronomist reviews")
                ->color($m['ai_accuracy_pct'] >= 85 ? 'success' : 'warning'),

            Stat::make('Farmer Satisfaction Score', "{$m['farmer_avg_rating_10']} / 10")
                ->description("{$m['farmer_thumbs_up_pct']}% Thumbs Up approval")
                ->color('success'),

            Stat::make('Lead Conversion & Reservations', "{$m['lead_conversion_pct']}%")
                ->description("Referral-to-Reservation rate: {$m['referral_to_reservation_pct']}% ({$m['total_reservations']} holds)")
                ->color('info'),

            Stat::make('Avg SLA Response Time', "Outlet: {$m['avg_outlet_response_minutes']}m | Rep: {$m['avg_rep_response_minutes']}m")
                ->description("{$m['active_outbreaks_count']} active ward outbreak alerts")
                ->color('warning'),
        ];
    }
}
