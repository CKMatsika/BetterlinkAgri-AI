<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\OrganisationType;
use App\Filament\Tenant\Resources\DemandAggregateResource\Pages;
use App\Models\DemandAggregate;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class DemandAggregateResource extends Resource
{
    protected static ?string $model = DemandAggregate::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Marketplace Intelligence';

    protected static ?string $navigationLabel = 'Demand Aggregates';

    public static function canAccess(): bool
    {
        $org = auth()->user()?->tenant;

        return $org !== null && $org->organisation_type !== OrganisationType::BANK;
    }

    public static function getEloquentQuery(): Builder
    {
        $org = auth()->user()?->tenant;
        if ($org === null || $org->organisation_type === OrganisationType::BANK) {
            return DemandAggregate::withoutGlobalScopes()->whereRaw('1 = 0');
        }

        if ($org->organisation_type === OrganisationType::GOVERNMENT_OFFICE) {
            return DemandAggregate::withoutGlobalScopes()
                ->whereNull('organisation_id')
                ->where('contributing_farmers_count', '>=', 5);
        }

        return DemandAggregate::withoutGlobalScopes()
            ->where('organisation_id', '=', $org->id);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('period_start', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('period_start')->date()->sortable(),
                Tables\Columns\TextColumn::make('period_end')->date(),
                Tables\Columns\TextColumn::make('granularity')->badge(),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('request_count')->label('Requests')->numeric()->sortable(),
                Tables\Columns\TextColumn::make('fulfilled_count')->label('Fulfilled')->numeric(),
                Tables\Columns\TextColumn::make('unmet_count')->label('Unmet')->numeric(),
                Tables\Columns\TextColumn::make('contributing_farmers_count')->label('Farmers (k)')->numeric(),
                Tables\Columns\TextColumn::make('total_sales_minor')
                    ->label('Sales Value')
                    ->formatStateUsing(fn (int $state, DemandAggregate $record): string => sprintf(
                        '%s %.2f',
                        $record->currency instanceof \BackedEnum ? $record->currency->value : (string) $record->currency,
                        $state / 100
                    )),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDemandAggregates::route('/'),
        ];
    }
}
