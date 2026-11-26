<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\OrganisationType;
use App\Filament\Tenant\Resources\StockSignalResource\Pages;
use App\Models\Product;
use App\Models\StockSignal;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class StockSignalResource extends Resource
{
    protected static ?string $model = StockSignal::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'Marketplace Intelligence';

    protected static ?string $navigationLabel = 'Unmet Stock Signals';

    public static function canAccess(): bool
    {
        $org = auth()->user()?->tenant;

        return $org !== null && $org->organisation_type !== OrganisationType::BANK;
    }

    public static function getEloquentQuery(): Builder
    {
        $org = auth()->user()?->tenant;
        if ($org === null || $org->organisation_type === OrganisationType::BANK) {
            return StockSignal::withoutGlobalScopes()->whereRaw('1 = 0');
        }

        if ($org->organisation_type === OrganisationType::MANUFACTURER) {
            $productIds = Product::withoutGlobalScopes()
                ->where('manufacturer_tenant_id', '=', $org->id)
                ->pluck('id')
                ->all();

            return $productIds === []
                ? StockSignal::withoutGlobalScopes()->whereRaw('1 = 0')
                : StockSignal::withoutGlobalScopes()->whereIn('product_id', $productIds);
        }

        if ($org->organisation_type === OrganisationType::DISTRIBUTOR) {
            return StockSignal::withoutGlobalScopes()
                ->whereIn('signal_type', ['out_of_stock_response', 'ring_exhausted', 'low_stock']);
        }

        return StockSignal::withoutGlobalScopes()->where('organisation_id', '=', $org->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('signal_type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ring_exhausted' => 'danger',
                        'out_of_stock_response' => 'warning',
                        default => 'info',
                    }),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('quantity_requested_scaled')
                    ->label('Qty Requested')
                    ->formatStateUsing(fn (?int $state): string => $state !== null ? number_format($state / 100, 1) : '-'),
                Tables\Columns\TextColumn::make('occurred_at')->dateTime()->sortable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockSignals::route('/'),
        ];
    }
}
