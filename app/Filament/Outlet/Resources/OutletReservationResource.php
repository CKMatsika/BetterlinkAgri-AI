<?php

declare(strict_types=1);

namespace App\Filament\Outlet\Resources;

use App\Actions\Outlets\RespondToReservationAction;
use App\Enums\CurrencyCode;
use App\Filament\Outlet\Resources\OutletReservationResource\Pages;
use App\Models\Reservation;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class OutletReservationResource extends Resource
{
    protected static ?string $model = Reservation::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Stock Reservations (30m SLA)';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Ref')
                    ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8))),
                Tables\Columns\TextColumn::make('outlet.name')->label('Outlet'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'confirmed', 'picked_up' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('sla_due_at')
                    ->label('30m SLA Due')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm_hold')
                    ->label('Confirm Hold')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Reservation $record): bool => $record->status === 'pending')
                    ->action(function (Reservation $record): void {
                        app(RespondToReservationAction::class)->confirmHold($record);
                        Notification::make()->title('Stock hold confirmed and farmer notified on WhatsApp.')->success()->send();
                    }),

                Tables\Actions\Action::make('reject_hold')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Reservation $record): bool => $record->status === 'pending')
                    ->form([
                        Forms\Components\TextInput::make('reason')
                            ->label('Reason for Rejection (e.g. Sold out of 50kg pack)')
                            ->required(),
                    ])
                    ->action(function (Reservation $record, array $data): void {
                        app(RespondToReservationAction::class)->rejectHold($record, (string) $data['reason']);
                        Notification::make()->title('Reservation rejected; next nearest outlet offered to farmer.')->warning()->send();
                    }),

                Tables\Actions\Action::make('mark_sold')
                    ->label('Mark Bought / Sold')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->visible(fn (Reservation $record): bool => in_array($record->status, ['pending', 'confirmed'], true))
                    ->form([
                        Forms\Components\Select::make('currency')
                            ->options([
                                CurrencyCode::USD->value => 'Paid in USD ($)',
                                CurrencyCode::ZIG->value => 'Paid in ZiG',
                            ])
                            ->default(CurrencyCode::USD->value)
                            ->required(),
                    ])
                    ->action(function (Reservation $record, array $data): void {
                        $curr = CurrencyCode::tryFrom((string) $data['currency']) ?? CurrencyCode::USD;
                        app(RespondToReservationAction::class)->markFulfilledAndRecordAttribution($record, $curr);
                        Notification::make()->title('Sale recorded & attributed to AI conversation.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOutletReservations::route('/'),
        ];
    }
}
