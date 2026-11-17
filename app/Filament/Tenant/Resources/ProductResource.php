<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Catalogue & Knowledge Base';

    protected static ?string $navigationLabel = 'Product Catalogue & Doses';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Product Identity & Zimbabwe Registration')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('sku')
                        ->label('SKU Code')
                        ->required()
                        ->maxLength(60),
                    Forms\Components\TextInput::make('name')
                        ->label('Product Trade Name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('brand_name')
                        ->maxLength(120),
                    Forms\Components\Select::make('category')
                        ->options([
                            'insecticide' => 'Insecticide',
                            'fungicide' => 'Fungicide',
                            'herbicide' => 'Herbicide',
                            'acaricide' => 'Acaricide / Miticide',
                            'fertiliser' => 'Granular / Bulk Fertiliser',
                            'foliar' => 'Foliar Feed / Biostimulant',
                            'seed' => 'Certified Seed / Hybrid',
                            'animal_health' => 'Animal Health / Veterinary',
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('active_ingredient')
                        ->placeholder('e.g. Chlorantraniliprole 200 g/L SC')
                        ->maxLength(255),
                    Forms\Components\TextInput::make('mode_of_action_group')
                        ->label('IRAC / FRAC / HRAC / NPK Group')
                        ->placeholder('e.g. IRAC Group 28 (Diamide)')
                        ->maxLength(80),
                    Forms\Components\TextInput::make('registration_number')
                        ->label('Zimbabwe Registration No. (PPRI / FSF / MCAZ)')
                        ->required()
                        ->maxLength(80),
                    Forms\Components\DatePicker::make('registration_expiry')
                        ->label('Registration Expiry Date'),
                    Forms\Components\Select::make('hazard_colour_band')
                        ->options([
                            'green' => 'Green Band (Caution)',
                            'blue' => 'Blue Band (Moderate Hazard)',
                            'amber' => 'Amber Band (Harmful)',
                            'red' => 'Red Band (Dangerous)',
                            'purple' => 'Purple Band (Extremely Poisonous / Restricted)',
                        ])
                        ->default('green')
                        ->required(),
                    Forms\Components\TextInput::make('agronomic_fit_rank_score')
                        ->label('Agronomic Fit Score (1–100, never ranked by margin)')
                        ->numeric()
                        ->default(85.00)
                        ->required(),
                    Forms\Components\Toggle::make('is_restricted_use')
                        ->label('Restricted-Use Product (Requires Agronomist Approval)'),
                    Forms\Components\Toggle::make('is_active')
                        ->default(true),
                    Forms\Components\Textarea::make('differentiation_notes')
                        ->columnSpanFull(),
                ]),

            Forms\Components\Section::make('Pack Sizes & Dual Currency Pricing (USD & ZiG)')
                ->schema([
                    Forms\Components\Repeater::make('pack_sizes_json')
                        ->label('Available Pack Sizes')
                        ->columns(3)
                        ->schema([
                            Forms\Components\TextInput::make('size')
                                ->placeholder('e.g. 250ml, 1L, 50kg')
                                ->required(),
                            Forms\Components\TextInput::make('price_usd')
                                ->label('Price (USD)')
                                ->numeric()
                                ->prefix('US$')
                                ->required(),
                            Forms\Components\TextInput::make('price_zig')
                                ->label('Price (ZiG)')
                                ->numeric()
                                ->prefix('ZiG ')
                                ->required(),
                        ]),
                ]),

            Forms\Components\Section::make('Mandatory PPE & Environmental Safety Warnings')
                ->columns(2)
                ->schema([
                    Forms\Components\TagsInput::make('ppe_requirements_json')
                        ->label('Mandatory PPE Items')
                        ->default(['nitrile_gloves', 'respirator_mask', 'goggles', 'gumboots', 'long_overalls']),
                    Forms\Components\KeyValue::make('safety_warnings_json')
                        ->label('Bee, Waterway, Livestock & Mixing Warnings'),
                ]),

            Forms\Components\Section::make('Approved Crop × Target Pest/Disease Matrix (Deterministic Doses & PHI/REI)')
                ->schema([
                    Forms\Components\Repeater::make('targets')
                        ->relationship('targets')
                        ->columns(4)
                        ->schema([
                            Forms\Components\Select::make('crop_id')
                                ->relationship('crop', 'name_en')
                                ->label('Crop')
                                ->searchable(),
                            Forms\Components\Select::make('livestock_id')
                                ->relationship('livestock', 'name_en')
                                ->label('Livestock (if Vet)'),
                            Forms\Components\Select::make('target_type')
                                ->options([
                                    'pest' => 'Pest',
                                    'disease' => 'Disease',
                                    'weed' => 'Weed',
                                    'livestock_disease' => 'Livestock Disease',
                                    'livestock_parasite' => 'Livestock Parasite',
                                ])
                                ->required(),
                            Forms\Components\TextInput::make('target_code')
                                ->placeholder('e.g. fall_armyworm')
                                ->required(),
                            Forms\Components\TextInput::make('target_name_en')
                                ->label('Target Name (EN)')
                                ->required(),
                            Forms\Components\TextInput::make('target_name_sn')
                                ->label('Local Name (Shona)'),
                            Forms\Components\TextInput::make('target_name_nd')
                                ->label('Local Name (Ndebele)'),
                            Forms\Components\TextInput::make('dose_per_16l_knapsack_value')
                                ->label('Dose per 16L Knapsack')
                                ->numeric()
                                ->required(),
                            Forms\Components\Select::make('dose_per_16l_knapsack_unit')
                                ->options(['ml' => 'ml', 'g' => 'g'])
                                ->required(),
                            Forms\Components\TextInput::make('dose_per_ha_value')
                                ->label('Dose per Hectare')
                                ->numeric()
                                ->required(),
                            Forms\Components\Select::make('dose_per_ha_unit')
                                ->options(['ml' => 'ml', 'L' => 'L', 'g' => 'g', 'kg' => 'kg'])
                                ->required(),
                            Forms\Components\TextInput::make('water_volume_per_ha_litres')
                                ->label('Water L/ha')
                                ->numeric()
                                ->default(250),
                            Forms\Components\TextInput::make('phi_days')
                                ->label('PHI (Days)')
                                ->numeric()
                                ->required(),
                            Forms\Components\TextInput::make('rei_hours')
                                ->label('REI (Hours)')
                                ->numeric()
                                ->default(12)
                                ->required(),
                            Forms\Components\TextInput::make('reapplication_interval_days')
                                ->label('Re-application Interval (Days)')
                                ->numeric(),
                            Forms\Components\TextInput::make('mixing_order')
                                ->columnSpan(2),
                            Forms\Components\Textarea::make('application_timing_notes')
                                ->columnSpan(2),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sku')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('active_ingredient')->limit(30),
                Tables\Columns\TextColumn::make('mode_of_action_group')->badge()->color('info'),
                Tables\Columns\TextColumn::make('registration_number')->label('ZW Reg No'),
                Tables\Columns\TextColumn::make('agronomic_fit_rank_score')->label('Agronomic Fit')->sortable(),
                Tables\Columns\IconColumn::make('is_restricted_use')->label('Restricted')->boolean(),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->options([
                        'insecticide' => 'Insecticide',
                        'fungicide' => 'Fungicide',
                        'herbicide' => 'Herbicide',
                        'fertiliser' => 'Fertiliser',
                        'animal_health' => 'Animal Health',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
