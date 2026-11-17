<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\CurrencyCode;
use App\Filament\Tenant\Resources\MarketPriceResource\Pages;
use App\Models\MarketPrice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class MarketPriceResource extends Resource
{
    protected static ?string $model = MarketPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'Farm Planning & Benchmarks';

    protected static ?string $navigationLabel = 'Commodity Output Prices';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('commodity_code')->required(),
            Forms\Components\TextInput::make('commodity_name')->required(),
            Forms\Components\TextInput::make('region_or_district')->default('National')->required(),
            Forms\Components\TextInput::make('grade')->default('Standard')->required(),
            Forms\Components\TextInput::make('unit')->required(),
            Forms\Components\TextInput::make('price_minor')->label('Price (Minor Units / Cents)')->numeric()->required(),
            Forms\Components\Select::make('currency')
                ->options([CurrencyCode::USD->value => 'USD', CurrencyCode::ZIG->value => 'ZiG'])
                ->required(),
            Forms\Components\TextInput::make('source')->required(),
            Forms\Components\DatePicker::make('effective_date')->required(),
            Forms\Components\Select::make('status')
                ->options(['unverified' => 'Unverified', 'verified' => 'Verified'])
                ->default('unverified')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('commodity_name')->searchable(),
                Tables\Columns\TextColumn::make('region_or_district')->label('Region / District'),
                Tables\Columns\TextColumn::make('grade'),
                Tables\Columns\TextColumn::make('price_minor')
                    ->label('Price (Cents)')
                    ->formatStateUsing(fn ($state, MarketPrice $record): string => "{$record->currency->value} " . number_format(((int) $state) / 100, 2) . " / {$record->unit}"),
                Tables\Columns\TextColumn::make('source'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'verified' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('effective_date')->date(),
            ])
            ->actions([
                Tables\Actions\Action::make('verify')
                    ->label('Verify Price')
                    ->color('success')
                    ->visible(fn (MarketPrice $record): bool => $record->status !== 'verified')
                    ->action(function (MarketPrice $record): void {
                        $record->update(['status' => 'verified']);
                        Notification::make()->title('Market price verified.')->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketPrices::route('/'),
        ];
    }
}
