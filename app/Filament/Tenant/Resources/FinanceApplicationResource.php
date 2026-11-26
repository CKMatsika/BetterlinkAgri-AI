<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\OrganisationType;
use App\Filament\Tenant\Resources\FinanceApplicationResource\Pages;
use App\Models\ConsentEvent;
use App\Models\FinanceApplication;
use App\Models\User;
use App\Services\Marketplace\ForwardQuoteAndFinanceService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class FinanceApplicationResource extends Resource
{
    protected static ?string $model = FinanceApplication::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Offtake & Agri-Finance (M5)';

    protected static ?string $navigationLabel = 'Finance Applications';

    public static function canAccess(): bool
    {
        return auth()->user()?->tenant?->organisation_type === OrganisationType::BANK;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = auth()->user()?->tenant_id;
        if ($tenantId === null) {
            return FinanceApplication::query()->whereRaw('1 = 0');
        }

        // Enforce live CDPA bank_plan_sharing consent at query time
        $consentedFarmerIds = FinanceApplication::query()
            ->where('bank_organisation_id', '=', $tenantId)
            ->pluck('farmer_id')
            ->unique()
            ->filter(static fn (string $farmerId): bool => ConsentEvent::hasActiveConsent($tenantId, $farmerId, 'bank_plan_sharing'))
            ->values()
            ->all();

        if ($consentedFarmerIds === []) {
            return FinanceApplication::query()->whereRaw('1 = 0');
        }

        return FinanceApplication::query()
            ->where('bank_organisation_id', '=', $tenantId)
            ->whereIn('farmer_id', $consentedFarmerIds);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Application Ref')
                    ->formatStateUsing(fn (string $state): string => strtoupper(substr($state, 0, 8))),
                Tables\Columns\TextColumn::make('purpose')->badge(),
                Tables\Columns\TextColumn::make('requested_amount_minor')
                    ->label('Requested')
                    ->formatStateUsing(fn (int $state, FinanceApplication $record): string => sprintf(
                        '%s %.2f',
                        $record->currency instanceof \BackedEnum ? $record->currency->value : (string) $record->currency,
                        $state / 100
                    )),
                Tables\Columns\TextColumn::make('approved_amount_minor')
                    ->label('Approved')
                    ->formatStateUsing(fn (?int $state, FinanceApplication $record): string => $state !== null
                        ? sprintf('%s %.2f', $record->currency instanceof \BackedEnum ? $record->currency->value : (string) $record->currency, $state / 100)
                        : '-'),
                Tables\Columns\TextColumn::make('plan_snapshot_json.title')->label('Farm Plan'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'approved', 'disbursed' => 'success',
                        'declined', 'withdrawn' => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('review')
                    ->label('Review Application')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->visible(fn (FinanceApplication $record): bool => in_array($record->status, ['submitted', 'under_review'], true))
                    ->form([
                        Forms\Components\Select::make('decision')
                            ->options([
                                'approved' => 'Approve Financing',
                                'under_review' => 'Mark Under Review',
                                'declined' => 'Decline Application',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('approved_amount_minor')
                            ->label('Approved Amount (minor cents)')
                            ->numeric(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Decision Notes')
                            ->maxLength(1000),
                    ])
                    ->action(function (FinanceApplication $record, array $data): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(ForwardQuoteAndFinanceService::class)->reviewFinanceApplication(
                            $user,
                            $record,
                            (string) $data['decision'],
                            isset($data['approved_amount_minor']) && $data['approved_amount_minor'] !== '' ? (int) $data['approved_amount_minor'] : null,
                            $data['notes'] ?? null
                        );

                        Notification::make()->title('Finance application updated')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinanceApplications::route('/'),
        ];
    }
}
