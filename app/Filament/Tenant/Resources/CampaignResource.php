<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\CampaignResource\Pages;
use App\Models\Campaign;
use App\Services\Engagement\CampaignBroadcastService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class CampaignResource extends Resource
{
    protected static ?string $model = Campaign::class;

    protected static ?string $navigationIcon = 'heroicon-o-megaphone';

    protected static ?string $navigationGroup = 'Engagement & Campaigns';

    protected static ?string $navigationLabel = 'Campaigns & Outbreak Alerts';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->required(),
            Forms\Components\Select::make('campaign_type')
                ->options([
                    'outbreak_alert' => 'Ward Outbreak Alert',
                    'seasonal_advisory' => 'Seasonal Planting / Top-Dressing Reminder',
                    'pfumvudza_reminder' => 'Pfumvudza Holing & Mulching Reminder',
                    'stock_arrival' => 'New Stock Arrival Notification',
                    'event_invite' => 'Field Day / Webinar Invite',
                ])
                ->required(),
            Forms\Components\TextInput::make('whatsapp_template_name'),
            Forms\Components\Select::make('whatsapp_template_status')
                ->options([
                    'draft' => 'Draft',
                    'submitted' => 'Submitted to Meta',
                    'approved' => 'Approved',
                    'rejected' => 'Rejected',
                ])
                ->default('approved')
                ->required(),
            Forms\Components\Textarea::make('body_en')
                ->label('Message Body (English)')
                ->required()
                ->columnSpanFull(),
            Forms\Components\Textarea::make('body_sn')
                ->label('Message Body (ChiShona)')
                ->columnSpanFull(),
            Forms\Components\Textarea::make('body_nd')
                ->label('Message Body (isiNdebele)')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('campaign_type')->badge(),
                Tables\Columns\TextColumn::make('whatsapp_template_status')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\Action::make('launch')
                    ->label('Launch Broadcast')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    ->action(function (Campaign $record): void {
                        $count = app(CampaignBroadcastService::class)->launchCampaign($record);
                        Notification::make()
                            ->title("Broadcast queued for {$count} opted-in farmers.")
                            ->success()
                            ->send();
                    }),
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCampaigns::route('/'),
        ];
    }
}
