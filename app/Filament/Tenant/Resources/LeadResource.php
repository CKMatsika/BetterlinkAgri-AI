<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\LeadTemperature;
use App\Filament\Tenant\Resources\LeadResource\Pages;
use App\Models\Lead;
use App\Services\Crm\CrmManager;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class LeadResource extends Resource
{
    protected static ?string $model = Lead::class;

    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationGroup = 'Conversations & Leads';

    protected static ?string $navigationLabel = 'Opportunities & Leads';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Opportunity Details & Intent Signals')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('need_summary')
                        ->required(),
                    Forms\Components\Select::make('temperature')
                        ->options([
                            LeadTemperature::HOT->value => '🔥 Hot Lead',
                            LeadTemperature::WARM->value => '🌤️ Warm Lead',
                            LeadTemperature::COLD->value => '❄️ Cold Lead',
                        ])
                        ->required(),
                    Forms\Components\TextInput::make('intent_score')
                        ->numeric()
                        ->required(),
                    Forms\Components\TextInput::make('area_ha')
                        ->label('Field Size (ha)')
                        ->numeric(),
                    Forms\Components\TextInput::make('purchase_window'),
                    Forms\Components\TextInput::make('estimated_value')
                        ->numeric(),
                    Forms\Components\Select::make('status')
                        ->options([
                            'new' => 'New',
                            'accepted' => 'Accepted by Rep',
                            'contacted' => 'Contacted Farmer',
                            'quoted' => 'Quoted',
                            'won' => 'Won / Sold',
                            'lost' => 'Lost',
                        ])
                        ->required(),
                    Forms\Components\Select::make('assigned_user_id')
                        ->relationship('assignedUser', 'name')
                        ->label('Assigned Sales Rep'),
                    Forms\Components\Select::make('assigned_outlet_id')
                        ->relationship('assignedOutlet', 'name')
                        ->label('Assigned Outlet'),
                    Forms\Components\TagsInput::make('score_reasons_json')
                        ->label('Intent Score Reasons')
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('need_summary')->searchable(),
                Tables\Columns\TextColumn::make('crop.name_en')->label('Crop'),
                Tables\Columns\TextColumn::make('area_ha')->label('Area (ha)'),
                Tables\Columns\TextColumn::make('temperature')
                    ->badge()
                    ->color(fn (LeadTemperature $state): string => match ($state) {
                        LeadTemperature::HOT => 'danger',
                        LeadTemperature::WARM => 'warning',
                        LeadTemperature::COLD => 'info',
                    }),
                Tables\Columns\TextColumn::make('intent_score')->label('Score')->sortable(),
                Tables\Columns\TextColumn::make('estimated_value')
                    ->formatStateUsing(fn ($state, Lead $record): string => "{$record->currency->value} {$state}"),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('crm_status')->badge(),
                Tables\Columns\TextColumn::make('sla_due_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\Action::make('sync_crm')
                    ->label('Sync CRM')
                    ->icon('heroicon-o-arrow-path')
                    ->action(function (Lead $record): void {
                        $res = app(CrmManager::class)->syncLead($record);
                        Notification::make()
                            ->title("Synced via {$res['adapter']} ({$res['external_crm_id']})")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make()->label('Open Opportunity'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLeads::route('/'),
            'edit' => Pages\EditLead::route('/{record}/edit'),
        ];
    }
}
