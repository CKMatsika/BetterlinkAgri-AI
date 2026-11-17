<?php

declare(strict_types=1);

namespace App\Filament\Outlet\Resources;

use App\Enums\CurrencyCode;
use App\Enums\StockQuantityBand;
use App\Filament\Outlet\Resources\OutletStockResource\Pages;
use App\Models\OutletStock;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class OutletStockResource extends Resource
{
    protected static ?string $model = OutletStock::class;

    protected static ?string $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Shelf Stock & Prices';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('product_id')
                ->relationship('product', 'name')
                ->required(),
            Forms\Components\TextInput::make('pack_size')
                ->required(),
            Forms\Components\Select::make('quantity_band')
                ->options([
                    StockQuantityBand::IN_STOCK->value => '🟢 In Stock',
                    StockQuantityBand::LOW_STOCK->value => '🟡 Low Stock',
                    StockQuantityBand::OUT_OF_STOCK->value => '🔴 Out of Stock',
                ])
                ->required(),
            Forms\Components\TextInput::make('exact_quantity')
                ->label('Exact Units / Bags (Optional)')
                ->numeric(),
            Forms\Components\TextInput::make('price')
                ->numeric()
                ->required(),
            Forms\Components\Select::make('currency')
                ->options([
                    CurrencyCode::USD->value => 'USD ($)',
                    CurrencyCode::ZIG->value => 'ZiG',
                ])
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('outlet.name')->label('Outlet'),
                Tables\Columns\TextColumn::make('product.name')->label('Product')->searchable(),
                Tables\Columns\TextColumn::make('pack_size')->badge(),
                Tables\Columns\TextColumn::make('quantity_band')
                    ->badge()
                    ->color(fn (StockQuantityBand $state): string => match ($state) {
                        StockQuantityBand::IN_STOCK => 'success',
                        StockQuantityBand::LOW_STOCK => 'warning',
                        StockQuantityBand::OUT_OF_STOCK => 'danger',
                    }),
                Tables\Columns\TextColumn::make('exact_quantity')->label('Qty'),
                Tables\Columns\TextColumn::make('price')
                    ->formatStateUsing(fn ($state, OutletStock $record): string => "{$record->currency->value} {$state}"),
                Tables\Columns\TextColumn::make('last_updated_at')
                    ->label('Freshness')
                    ->since()
                    ->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('set_in_stock')
                    ->label('In Stock')
                    ->color('success')
                    ->action(function (OutletStock $record): void {
                        $record->update([
                            'quantity_band' => StockQuantityBand::IN_STOCK,
                            'source' => 'outlet_portal',
                            'last_updated_at' => now(),
                        ]);
                        Notification::make()->title('Marked In Stock')->success()->send();
                    }),
                Tables\Actions\Action::make('set_low_stock')
                    ->label('Low')
                    ->color('warning')
                    ->action(function (OutletStock $record): void {
                        $record->update([
                            'quantity_band' => StockQuantityBand::LOW_STOCK,
                            'source' => 'outlet_portal',
                            'last_updated_at' => now(),
                        ]);
                        Notification::make()->title('Marked Low Stock')->warning()->send();
                    }),
                Tables\Actions\Action::make('set_out_of_stock')
                    ->label('Out')
                    ->color('danger')
                    ->action(function (OutletStock $record): void {
                        $record->update([
                            'quantity_band' => StockQuantityBand::OUT_OF_STOCK,
                            'exact_quantity' => 0,
                            'source' => 'outlet_portal',
                            'last_updated_at' => now(),
                        ]);
                        Notification::make()->title('Marked Out of Stock')->danger()->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutletStocks::route('/'),
        ];
    }
}
