<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\DemandAggregateResource\Pages;

use App\Filament\Tenant\Resources\DemandAggregateResource;
use App\Services\Marketplace\DemandAggregationService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

final class ListDemandAggregates extends ListRecords
{
    protected static string $resource = DemandAggregateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recalculate_weekly')
                ->label('Refresh 7-Day Rollup')
                ->icon('heroicon-o-arrow-path')
                ->action(function (): void {
                    $counts = app(DemandAggregationService::class)->aggregatePeriod(now()->subDays(6), now(), 'weekly');
                    Notification::make()
                        ->title("Rollup updated (area: {$counts['area_rows']}, brand: {$counts['manufacturer_rows']}, retail: {$counts['retailer_rows']})")
                        ->success()
                        ->send();
                }),
        ];
    }
}
