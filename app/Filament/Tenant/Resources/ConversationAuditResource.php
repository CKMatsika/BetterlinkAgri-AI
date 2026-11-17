<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\ConversationMode;
use App\Enums\ConversationState;
use App\Filament\Tenant\Resources\ConversationAuditResource\Pages;
use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\AuditLog;
use App\Models\Conversation;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Section 10 & 11: Conversation Review, Live Human Takeover, Connected Farmer Profile Panel & Audit Log Viewer.
 */
final class ConversationAuditResource extends Resource
{
    protected static ?string $model = Conversation::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'Conversations & Leads';

    protected static ?string $navigationLabel = 'Conversations & Audit Logs';

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_message_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('context_chip')
                    ->label('Context Chip')
                    ->badge()
                    ->color('success')
                    ->default('General Inquiry'),
                Tables\Columns\TextColumn::make('channel')->badge(),
                Tables\Columns\TextColumn::make('language')->badge(),
                Tables\Columns\TextColumn::make('state')->badge()->color('info'),
                Tables\Columns\TextColumn::make('mode')
                    ->badge()
                    ->color(fn (ConversationMode $state): string => $state === ConversationMode::HUMAN_TAKEOVER ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('last_message_at')->dateTime()->sortable(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Review & Profile Panel'),

                Tables\Actions\Action::make('takeover')
                    ->label('Take Over Live Chat')
                    ->icon('heroicon-o-user-plus')
                    ->color('warning')
                    ->visible(fn (Conversation $record): bool => $record->mode === ConversationMode::AI_ACTIVE)
                    ->form([
                        Forms\Components\Textarea::make('message_to_farmer')
                            ->label('Send Live Message to Farmer (AI will pause)')
                            ->required(),
                    ])
                    ->action(function (Conversation $record, array $data): void {
                        $prevState = $record->state;
                        $record->update([
                            'mode' => ConversationMode::HUMAN_TAKEOVER,
                            'state' => ConversationState::HUMAN_TAKEOVER,
                            'assigned_user_id' => auth()->id(),
                            'state_updated_at' => now(),
                        ]);

                        SendOutboundChannelMessageJob::dispatch(
                            $record->id,
                            (string) $data['message_to_farmer'],
                            [],
                            'sales_rep'
                        );

                        AuditLog::query()->create([
                            'tenant_id' => $record->tenant_id,
                            'conversation_id' => $record->id,
                            'user_id' => auth()->id(),
                            'event_type' => 'human_takeover',
                            'from_state' => $prevState->value,
                            'to_state' => ConversationState::HUMAN_TAKEOVER->value,
                            'created_at' => now(),
                        ]);

                        Notification::make()->title('Human takeover active. AI paused.')->success()->send();
                    }),

                Tables\Actions\Action::make('return_to_ai')
                    ->label('Return to AI')
                    ->icon('heroicon-o-sparkles')
                    ->color('success')
                    ->visible(fn (Conversation $record): bool => $record->mode === ConversationMode::HUMAN_TAKEOVER)
                    ->action(function (Conversation $record): void {
                        $record->update([
                            'mode' => ConversationMode::AI_ACTIVE,
                            'state' => ConversationState::FOLLOW_UP_SCHEDULED,
                            'state_updated_at' => now(),
                        ]);

                        AuditLog::query()->create([
                            'tenant_id' => $record->tenant_id,
                            'conversation_id' => $record->id,
                            'user_id' => auth()->id(),
                            'event_type' => 'state_transition',
                            'from_state' => ConversationState::HUMAN_TAKEOVER->value,
                            'to_state' => ConversationState::FOLLOW_UP_SCHEDULED->value,
                            'metadata_json' => ['reason' => 'returned_to_ai_by_human'],
                            'created_at' => now(),
                        ]);

                        Notification::make()->title('Conversation returned to AI agent.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListConversationAudits::route('/'),
            'view' => Pages\ViewConversationAudit::route('/{record}'),
        ];
    }
}
