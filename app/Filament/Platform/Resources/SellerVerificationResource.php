<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Filament\Platform\Resources\SellerVerificationResource\Pages;
use App\Models\SellerVerification;
use App\Models\User;
use App\Services\Marketplace\MarketplaceTrustAndDisputeService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class SellerVerificationResource extends Resource
{
    protected static ?string $model = SellerVerification::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Marketplace Governance';

    protected static ?string $navigationLabel = 'Seller Verifications';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('organisation.name')
                    ->label('Organisation')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('business_registration_number')
                    ->label('Reg Number')
                    ->searchable(),
                Tables\Columns\TextColumn::make('verified_address')
                    ->label('Address')
                    ->limit(40),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'verified' => 'success',
                        'suspended' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('reviewed_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('rejection_or_suspension_reason')
                    ->label('Review Note')
                    ->limit(40),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (SellerVerification $record): bool => $record->status !== 'verified')
                    ->requiresConfirmation()
                    ->action(function (SellerVerification $record): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(MarketplaceTrustAndDisputeService::class)
                            ->reviewSellerVerification($admin, $record, 'verified', 'Approved via Platform Panel');

                        Notification::make()->title('Seller verified')->success()->send();
                    }),
                Tables\Actions\Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (SellerVerification $record): bool => $record->status !== 'suspended')
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label('Suspension Reason')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (SellerVerification $record, array $data): void {
                        /** @var User $admin */
                        $admin = auth()->user();
                        app(MarketplaceTrustAndDisputeService::class)
                            ->reviewSellerVerification($admin, $record, 'suspended', (string) $data['reason']);

                        Notification::make()->title('Seller suspended and excluded from broadcasts')->danger()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSellerVerifications::route('/'),
        ];
    }
}
