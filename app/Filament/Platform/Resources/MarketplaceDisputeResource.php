<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\MarketplaceDisputeResource\Pages;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Services\Marketplace\MarketplaceTrustAndDisputeService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class MarketplaceDisputeResource extends Resource
{
    protected static ?string $model = MarketplaceOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Marketplace Governance';

    protected static ?string $navigationLabel = 'Order Disputes';

    public static function getEloquentQuery(): Builder
    {
        return MarketplaceOrder::withoutGlobalScopes()
            ->with(['outlet'])
            ->whereIn('status', ['disputed', 'cancelled', 'completed']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Order Ref')
                    ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8))),
                Tables\Columns\TextColumn::make('outlet.name')
                    ->label('Outlet')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'disputed' => 'danger',
                        'completed' => 'success',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('total_amount_minor')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state, MarketplaceOrder $record): string => sprintf(
                        '%s %.2f',
                        $record->currency instanceof \BackedEnum ? $record->currency->value : (string) $record->currency,
                        $state / 100
                    )),
                Tables\Columns\TextColumn::make('payment_status')->badge(),
                Tables\Columns\TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('resolve')
                    ->label('Resolve Dispute')
                    ->icon('heroicon-o-check-badge')
                    ->color('warning')
                    ->visible(fn (MarketplaceOrder $record): bool => $record->status === 'disputed')
                    ->form([
                        Forms\Components\Select::make('resolution')
                            ->options([
                                'complete' => 'Resolve & Complete Order',
                                'cancel' => 'Cancel Order & Refund Payment',
                            ])
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Resolution Notes')
                            ->required()
                            ->maxLength(1000),
                        Forms\Components\Toggle::make('suspend_seller')
                            ->label('Suspend seller from future marketplace broadcasts')
                            ->default(false),
                    ])
                    ->action(function (MarketplaceOrder $record, array $data): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(MarketplaceTrustAndDisputeService::class)->resolveDispute(
                            $admin,
                            $record,
                            (string) $data['resolution'],
                            (string) $data['notes'],
                            (bool) ($data['suspend_seller'] ?? false)
                        );

                        Notification::make()->title('Dispute resolved and logged to audit trail')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMarketplaceDisputes::route('/'),
        ];
    }
}
