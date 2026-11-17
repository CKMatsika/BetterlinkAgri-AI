<?php

declare(strict_types=1);

namespace App\Filament\Platform\Resources;

use App\Enums\RecommendationScope;
use App\Enums\TenantType;
use App\Filament\Platform\Resources\TenantResource\Pages;
use App\Models\Tenant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Platform Tenancy';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Tenant Identity & Supply Role')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('slug')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(120),
                    Forms\Components\Select::make('tenant_type')
                        ->options([
                            TenantType::MANUFACTURER->value => TenantType::MANUFACTURER->label(),
                            TenantType::DISTRIBUTOR->value => TenantType::DISTRIBUTOR->label(),
                            TenantType::AGRO_DEALER->value => TenantType::AGRO_DEALER->label(),
                        ])
                        ->required(),
                    Forms\Components\Select::make('recommendation_scope')
                        ->options([
                            RecommendationScope::OWN_BRANDS_ONLY->value => RecommendationScope::OWN_BRANDS_ONLY->label(),
                            RecommendationScope::OWN_PLUS_PARTNER_BRANDS->value => RecommendationScope::OWN_PLUS_PARTNER_BRANDS->label(),
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('usd_to_zig_rate')
                        ->label('Indicative USD -> ZiG Exchange Rate')
                        ->numeric()
                        ->default(25.5000)
                        ->required(),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                ]),

            Forms\Components\Section::make('WhatsApp Cloud API & Persona Configuration')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('whatsapp_phone_number_id')
                        ->label('Meta WhatsApp Phone Number ID')
                        ->maxLength(120),
                    Forms\Components\TextInput::make('whatsapp_business_account_id')
                        ->label('WhatsApp Business Account ID')
                        ->maxLength(120),
                    Forms\Components\TextInput::make('whatsapp_access_token_encrypted')
                        ->label('WhatsApp Permanent Access Token (Encrypted at Rest)')
                        ->password()
                        ->revealable()
                        ->columnSpanFull(),
                    Forms\Components\KeyValue::make('persona_config')
                        ->label('Agent Persona, Tone, Languages & Branding')
                        ->keyLabel('Setting Key')
                        ->valueLabel('Value')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('CRM / ERP Deployment Mode')
                ->schema([
                    Forms\Components\KeyValue::make('crm_config')
                        ->label('CRM Mode (standalone | integrated) & Adapter Settings (csv | webhook | hubspot | zoho)')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->badge(),
                Tables\Columns\TextColumn::make('tenant_type')->badge(),
                Tables\Columns\TextColumn::make('recommendation_scope')->badge(),
                Tables\Columns\TextColumn::make('usd_to_zig_rate')->label('USD/ZiG Rate'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
