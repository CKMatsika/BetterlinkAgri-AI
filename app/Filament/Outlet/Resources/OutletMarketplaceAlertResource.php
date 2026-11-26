<?php

declare(strict_types=1);

namespace App\Filament\Outlet\Resources;

use App\Filament\Outlet\Resources\OutletMarketplaceAlertResource\Pages;
use App\Models\OutletStock;
use App\Models\RequestRecipient;
use App\Services\Marketplace\MarketplaceBroadcastService;
use App\Services\Marketplace\MarketplaceOfferAndOrderService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class OutletMarketplaceAlertResource extends Resource
{
    protected static ?string $model = RequestRecipient::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Live Marketplace Alerts';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return RequestRecipient::withoutGlobalScopes()
            ->with(['request.items', 'outlet'])
            ->when(
                $user !== null && $user->tenant_id !== null,
                fn (Builder $q) => $q->where('organisation_id', '=', $user->tenant_id),
                fn (Builder $q) => $q->whereRaw('1 = 0')
            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('10s')
            ->defaultSort('notified_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('request_id')
                    ->label('Request Ref')
                    ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8))),
                Tables\Columns\TextColumn::make('outlet.name')->label('Outlet'),
                Tables\Columns\TextColumn::make('distance_band')->label('Distance'),
                Tables\Columns\TextColumn::make('ring_number')->label('Ring')->badge(),
                Tables\Columns\TextColumn::make('response')
                    ->label('Response')
                    ->default('pending')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'offered' => 'success',
                        'declined', 'out_of_stock' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('opened_at')->label('Opened')->dateTime(),
                Tables\Columns\TextColumn::make('notified_at')->label('Alerted')->dateTime(),
            ])
            ->actions([
                Tables\Actions\Action::make('make_offer')
                    ->label('Make Offer')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('success')
                    ->visible(fn (RequestRecipient $record): bool => $record->response === null)
                    ->form(function (RequestRecipient $record): array {
                        $firstItem = $record->request?->items->first();
                        $stockPriceMinor = 0;
                        if ($firstItem?->product_id) {
                            $stock = OutletStock::withoutGlobalScopes()
                                ->where('outlet_id', '=', $record->outlet_id)
                                ->where('product_id', '=', $firstItem->product_id)
                                ->first();
                            if ($stock !== null) {
                                $stockPriceMinor = (int) round(((float) $stock->price) * 100);
                            }
                        }

                        return [
                            Forms\Components\TextInput::make('unit_price_minor')
                                ->label('Unit Price (minor cents)')
                                ->numeric()
                                ->required()
                                ->default($stockPriceMinor > 0 ? $stockPriceMinor : 1000),
                            Forms\Components\TextInput::make('total_price_minor')
                                ->label('Total Price (minor cents)')
                                ->numeric()
                                ->required()
                                ->default($stockPriceMinor > 0 ? $stockPriceMinor : 1000),
                            Forms\Components\Select::make('fulfilment_type')
                                ->options(['pickup' => 'Pickup', 'delivery' => 'Delivery', 'both' => 'Both'])
                                ->default('pickup')
                                ->required(),
                            Forms\Components\TextInput::make('delivery_fee_minor')
                                ->label('Delivery Fee (minor cents)')
                                ->numeric()
                                ->default(0),
                            Forms\Components\Textarea::make('note')->label('Note'),
                        ];
                    })
                    ->action(function (RequestRecipient $record, array $data): void {
                        app(MarketplaceOfferAndOrderService::class)->submitOffer($record, [
                            'unit_price_minor' => (int) $data['unit_price_minor'],
                            'total_price_minor' => (int) $data['total_price_minor'],
                            'quantity_available_scaled' => (int) ($record->request?->items->first()?->quantity_scaled ?? 100),
                            'fulfilment_type' => (string) $data['fulfilment_type'],
                            'delivery_fee_minor' => (int) ($data['delivery_fee_minor'] ?? 0),
                            'note' => $data['note'] ?? null,
                            'submitted_by_user_id' => auth()->id(),
                        ]);
                        Notification::make()->title('Offer submitted to farmer.')->success()->send();
                    }),

                Tables\Actions\Action::make('out_of_stock')
                    ->label('Out of Stock')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (RequestRecipient $record): bool => $record->response === null)
                    ->action(function (RequestRecipient $record): void {
                        app(MarketplaceBroadcastService::class)->recordRecipientResponse($record, 'out_of_stock');
                        Notification::make()->title('Marked out of stock; signal recorded.')->warning()->send();
                    }),

                Tables\Actions\Action::make('decline')
                    ->label('Decline')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (RequestRecipient $record): bool => $record->response === null)
                    ->action(function (RequestRecipient $record): void {
                        app(MarketplaceBroadcastService::class)->recordRecipientResponse($record, 'declined');
                        Notification::make()->title('Request declined.')->info()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutletMarketplaceAlerts::route('/'),
        ];
    }
}
