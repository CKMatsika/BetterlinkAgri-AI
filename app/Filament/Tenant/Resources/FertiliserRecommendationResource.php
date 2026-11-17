<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\FertiliserRecommendationResource\Pages;
use App\Models\FertiliserRecommendation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class FertiliserRecommendationResource extends Resource
{
    protected static ?string $model = FertiliserRecommendation::class;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Catalogue & Knowledge Base';

    protected static ?string $navigationLabel = 'Fertiliser Rate Tables';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Deterministic Crop Nutrition & Pfumvudza Rate Matrix')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('crop_id')
                        ->relationship('crop', 'name_en')
                        ->required(),
                    Forms\Components\Select::make('product_id')
                        ->relationship('product', 'name')
                        ->label('Linked Catalogue Fertiliser SKU'),
                    Forms\Components\Select::make('nutrient_addressed')
                        ->options([
                            'N' => 'Nitrogen (N)',
                            'P' => 'Phosphorus (P)',
                            'K' => 'Potassium (K)',
                            'S' => 'Sulphur (S)',
                            'Zn' => 'Zinc (Zn)',
                            'B' => 'Boron (B)',
                            'Mg' => 'Magnesium (Mg)',
                            'Ca_Lime' => 'Calcium / Agricultural Lime',
                            'compound_basal' => 'Compound NPK Basal',
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('fertiliser_type_name')
                        ->placeholder('e.g. Ammonium Nitrate (AN 34.5% N)')
                        ->required(),
                    Forms\Components\TextInput::make('growth_stage')
                        ->placeholder('e.g. basal_planting, v4_v6_topdress')
                        ->required(),
                    Forms\Components\Select::make('natural_region')
                        ->options([
                            'ALL' => 'All Natural Regions (I–V)',
                            'I' => 'Natural Region I',
                            'IIa' => 'Natural Region IIa',
                            'IIb' => 'Natural Region IIb',
                            'III' => 'Natural Region III',
                            'IV' => 'Natural Region IV',
                            'V' => 'Natural Region V',
                        ])
                        ->default('ALL')
                        ->required(),
                    Forms\Components\TextInput::make('rate_grams_per_plant')
                        ->label('Rate per Plant (Grams)')
                        ->numeric()
                        ->required(),
                    Forms\Components\TextInput::make('rate_grams_per_pfumvudza_station')
                        ->label('Rate per Pfumvudza Station (Grams / 2-plant hole)')
                        ->numeric()
                        ->required(),
                    Forms\Components\TextInput::make('rate_kg_per_ha')
                        ->label('Rate per Hectare (kg/ha)')
                        ->numeric()
                        ->required(),
                    Forms\Components\TextInput::make('local_measure_equivalent')
                        ->label('Local Cup / Bottle-Cap Equivalent')
                        ->placeholder('e.g. 1 level Nr. 5 cup (5g) per plant')
                        ->columnSpan(2)
                        ->required(),
                    Forms\Components\Select::make('placement_method')
                        ->options([
                            'side_dress' => 'Side-Dress (5–7cm from stem)',
                            'dollop_station' => 'Dollop / Pfumvudza Planting Basin',
                            'banding' => 'Banding in Furrow',
                            'ring_placement' => 'Ring Placement',
                            'foliar_spray' => 'Foliar Spray',
                            'broadcast_incorporate' => 'Broadcast & Incorporate',
                        ])
                        ->required(),
                    Forms\Components\Textarea::make('timing_notes')
                        ->columnSpanFull(),
                    Forms\Components\Textarea::make('precautions_notes')
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('crop.name_en')->label('Crop')->sortable(),
                Tables\Columns\TextColumn::make('nutrient_addressed')->badge(),
                Tables\Columns\TextColumn::make('fertiliser_type_name')->searchable(),
                Tables\Columns\TextColumn::make('growth_stage'),
                Tables\Columns\TextColumn::make('rate_grams_per_plant')->label('g / Plant'),
                Tables\Columns\TextColumn::make('rate_grams_per_pfumvudza_station')->label('g / Pfumvudza Hole'),
                Tables\Columns\TextColumn::make('rate_kg_per_ha')->label('kg / Ha'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFertiliserRecommendations::route('/'),
        ];
    }
}
