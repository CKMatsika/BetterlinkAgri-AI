<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\ProgrammeResource\Pages;
use App\Models\Programme;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class ProgrammeResource extends Resource
{
    protected static ?string $model = Programme::class;

    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Engagement & Campaigns';

    protected static ?string $navigationLabel = 'Crop Programmes';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')
                ->placeholder('e.g. Tomato Nutrition & Blight Programme')
                ->required(),
            Forms\Components\Select::make('crop_id')
                ->relationship('crop', 'name_en')
                ->required(),
            Forms\Components\TextInput::make('target_stage')
                ->placeholder('e.g. transplanting_to_fruiting'),
            Forms\Components\Toggle::make('is_active')
                ->default(true),
            Forms\Components\KeyValue::make('stage_messages_json')
                ->label('Stage-Based Advisory Sequence (Stage Key -> Message)')
                ->columnSpanFull(),
            Forms\Components\KeyValue::make('monthly_priority_question_json')
                ->label('Monthly "Main Priority" Question (en / sn / nd)')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('crop.name_en')->label('Crop'),
                Tables\Columns\TextColumn::make('target_stage'),
                Tables\Columns\IconColumn::make('is_active')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProgrammes::route('/'),
        ];
    }
}
