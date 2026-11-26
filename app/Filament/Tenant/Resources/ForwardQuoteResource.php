<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\OrganisationType;
use App\Filament\Tenant\Resources\ForwardQuoteResource\Pages;
use App\Models\ForwardQuote;
use App\Models\User;
use App\Services\Marketplace\ForwardQuoteAndFinanceService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

final class ForwardQuoteResource extends Resource
{
    protected static ?string $model = ForwardQuote::class;

    protected static bool $isScopedToTenant = false;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationGroup = 'Offtake & Agri-Finance (M5)';

    protected static ?string $navigationLabel = 'Forward Quotes';

    public static function canAccess(): bool
    {
        return auth()->user()?->tenant?->organisation_type === OrganisationType::OFFTAKER;
    }

    public static function getEloquentQuery(): Builder
    {
        $tenantId = auth()->user()?->tenant_id;

        return $tenantId !== null
            ? ForwardQuote::query()->with(['farmer'])->where('offtaker_organisation_id', '=', $tenantId)
            : ForwardQuote::query()->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('commodity_name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('grade')->badge(),
                Tables\Columns\TextColumn::make('quantity_scaled')
                    ->label('Quantity')
                    ->formatStateUsing(fn (int $state, ForwardQuote $record): string => number_format($state / 100, 2) . ' ' . $record->unit),
                Tables\Columns\TextColumn::make('total_value_minor')
                    ->label('Total Quote')
                    ->formatStateUsing(fn (?int $state, ForwardQuote $record): string => $state !== null
                        ? sprintf('%s %.2f', $record->currency instanceof \BackedEnum ? $record->currency->value : (string) $record->currency, $state / 100)
                        : 'Pending'),
                Tables\Columns\TextColumn::make('delivery_window_start')->label('Delivery From')->date(),
                Tables\Columns\TextColumn::make('farmer_contact_revealed_at')
                    ->label('Farmer Contact')
                    ->formatStateUsing(fn ($state, ForwardQuote $record): string => $record->farmer_contact_revealed_at !== null
                        ? (string) ($record->farmer?->phone_encrypted ?? 'Consented')
                        : 'Hidden (No Consent)'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'accepted', 'contracted' => 'success',
                        'quoted' => 'info',
                        'declined', 'expired' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('submit_quote')
                    ->label('Submit Price Quote')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn (ForwardQuote $record): bool => $record->status === 'requested')
                    ->form([
                        Forms\Components\TextInput::make('quoted_unit_price_minor')
                            ->label('Unit Price (minor cents, e.g. 38000 = $380.00)')
                            ->numeric()
                            ->required(),
                        Forms\Components\TextInput::make('valid_hours')
                            ->label('Valid For (Hours)')
                            ->numeric()
                            ->default(72)
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Delivery & Grade Notes')
                            ->maxLength(500),
                    ])
                    ->action(function (ForwardQuote $record, array $data): void {
                        /** @var User $user */
                        $user = auth()->user();
                        app(ForwardQuoteAndFinanceService::class)->submitOfftakerQuote(
                            $user,
                            $record,
                            (int) $data['quoted_unit_price_minor'],
                            (int) $data['valid_hours'],
                            $data['notes'] ?? null
                        );

                        Notification::make()->title('Forward quote submitted to farmer')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListForwardQuotes::route('/'),
        ];
    }
}
