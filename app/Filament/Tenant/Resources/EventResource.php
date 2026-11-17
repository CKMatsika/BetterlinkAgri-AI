<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\EventResource\Pages;
use App\Models\Event;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Engagement & Campaigns';

    protected static ?string $navigationLabel = 'Field Days, Demos & Webinars';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('title')
                ->placeholder('e.g. Glendale Maize Field Day & Sprayer Calibration Demo')
                ->required(),
            Forms\Components\Select::make('event_type')
                ->options([
                    'field_day' => 'Field Day',
                    'demo' => 'Agronomy Demonstration',
                    'webinar' => 'Online / WhatsApp Webinar',
                ])
                ->required(),
            Forms\Components\Select::make('crop_id')
                ->relationship('crop', 'name_en'),
            Forms\Components\Select::make('ward_id')
                ->relationship('ward', 'name')
                ->searchable(),
            Forms\Components\TextInput::make('venue_or_link')
                ->required(),
            Forms\Components\DateTimePicker::make('starts_at')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')->searchable(),
                Tables\Columns\TextColumn::make('event_type')->badge(),
                Tables\Columns\TextColumn::make('ward.name')->label('Ward'),
                Tables\Columns\TextColumn::make('venue_or_link'),
                Tables\Columns\TextColumn::make('starts_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEvents::route('/'),
        ];
    }
}
