<?php

declare(strict_types=1);

namespace App\Filament\Platform\Widgets;

use App\Models\SalesAttribution;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Section 14: Manufacturer Sell-Through by Distributor & District Widget.
 */
final class PlatformSellThroughWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalPlatformSalesUsd = (float) SalesAttribution::withoutGlobalScopes()
            ->where('currency', '=', 'USD')
            ->sum('total_amount');

        $totalPlatformSalesZig = (float) SalesAttribution::withoutGlobalScopes()
            ->where('currency', '=', 'ZIG')
            ->sum('total_amount');

        $totalUnitsMoved = (float) SalesAttribution::withoutGlobalScopes()->sum('quantity_sold');

        return [
            Stat::make('Network Sell-Through (USD)', 'US$' . number_format($totalPlatformSalesUsd, 2))
                ->description('Attributed across all Manufacturer -> Distributor -> Outlet links')
                ->color('success'),
            Stat::make('Network Sell-Through (ZiG)', 'ZiG ' . number_format($totalPlatformSalesZig, 2))
                ->description('Dual-currency verified outlet transactions')
                ->color('info'),
            Stat::make('Total Packs / Bags Moved', number_format($totalUnitsMoved, 0))
                ->description('Closed-loop AI conversation -> outlet -> product attribution')
                ->color('primary'),
        ];
    }
}
