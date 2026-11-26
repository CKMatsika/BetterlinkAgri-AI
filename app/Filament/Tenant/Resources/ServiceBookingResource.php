<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\ServiceBookingResource\Pages;
use App\Models\ServiceBooking;
use App\Models\User;
use App\Services\Marketplace\ServiceBookingService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ServiceBookingResource extends Resource
{
    protected static ?string $model = ServiceBooking::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Services & Logistics (M4)';

    protected static ?string $navigationLabel = 'Service Bookings';

    public static function getEloquentQuery(): Builder
    {
        $tenantId = auth()->user()?->tenant_id;

        return $tenantId !== null
            ? ServiceBooking::query()->with(['serviceProvider', 'farmer'])->where('organisation_id', '=', $tenantId)
            : ServiceBooking::query()->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_date', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('serviceProvider.name')->label('Provider')->searchable(),
                Tables\Columns\TextColumn::make('service_type')->badge(),
                Tables\Columns\TextColumn::make('scheduled_date')->date()->sortable(),
                Tables\Columns\TextColumn::make('quantity_scaled')
                    ->label('Quantity')
                    ->formatStateUsing(fn (int $state, ServiceBooking $record): string => number_format($state / 100, 1) . ' ' . $record->unit),
                Tables\Columns\TextColumn::make('quoted_amount_minor')
                    ->label('Quote')
                    ->formatStateUsing(fn (int $state, ServiceBooking $record): string => sprintf(
                        '%s %.2f',
                        $record->currency instanceof \BackedEnum ? $record->currency->value : (string) $record->currency,
                        $state / 100
                    )),
                Tables\Columns\TextColumn::make('farmer_contact_revealed_at')
                    ->label('Farmer Contact')
                    ->formatStateUsing(fn ($state, ServiceBooking $record): string => $record->farmer_contact_revealed_at !== null
                        ? (string) ($record->farmer?->phone_encrypted ?? 'Consented')
                        : 'Hidden (No Consent)'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'completed' => 'success',
                        'confirmed', 'in_progress' => 'info',
                        'cancelled', 'disputed' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Confirm')
                    ->color('info')
                    ->visible(fn (ServiceBooking $record): bool => $record->status === 'requested')
                    ->action(function (ServiceBooking $record): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(ServiceBookingService::class)->transitionBooking($record, 'confirmed', 'provider', $user->id);
                        Notification::make()->title('Booking confirmed')->success()->send();
                    }),
                Tables\Actions\Action::make('start')
                    ->label('Start Job')
                    ->color('primary')
                    ->visible(fn (ServiceBooking $record): bool => $record->status === 'confirmed')
                    ->action(function (ServiceBooking $record): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(ServiceBookingService::class)->transitionBooking($record, 'in_progress', 'provider', $user->id);
                        Notification::make()->title('Service job in progress')->info()->send();
                    }),
                Tables\Actions\Action::make('complete')
                    ->label('Complete')
                    ->color('success')
                    ->visible(fn (ServiceBooking $record): bool => $record->status === 'in_progress')
                    ->action(function (ServiceBooking $record): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(ServiceBookingService::class)->transitionBooking($record, 'completed', 'provider', $user->id);
                        Notification::make()->title('Service booking completed')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServiceBookings::route('/'),
        ];
    }
}
