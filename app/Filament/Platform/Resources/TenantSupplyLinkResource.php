<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Models\TenantSupplyLink;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class TenantSupplyLinkResource extends Resource
{
    protected static ?string $model = TenantSupplyLink::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationGroup = 'Platform Tenancy';

    protected static ?string $navigationLabel = 'Supply Network Links';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('manufacturer_tenant_id')
                ->relationship('manufacturerTenant', 'name')
                ->label('Manufacturer / Principal Tenant')
                ->searchable()
                ->required(),
            Forms\Components\Select::make('distributor_or_retailer_tenant_id')
                ->relationship('partnerTenant', 'name')
                ->label('Distributor / Agro-Dealer Tenant')
                ->searchable()
                ->required(),
            Forms\Components\Select::make('status')
                ->options([
                    'pending' => 'Pending Approval',
                    'active' => 'Active (Catalogue Inherited)',
                    'suspended' => 'Suspended',
                    'revoked' => 'Revoked',
                ])
                ->default('active')
                ->required(),
            Forms\Components\TagsInput::make('approved_product_ids_json')
                ->label('Approved Product UUIDs (Leave empty to inherit all active manufacturer SKUs)')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('manufacturerTenant.name')
                    ->label('Manufacturer')
                    ->searchable(),
                Tables\Columns\TextColumn::make('partnerTenant.name')
                    ->label('Distributor / Agro-Dealer')
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        default => 'danger',
                    }),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenantSupplyLinks::route('/'),
        ];
    }
}
