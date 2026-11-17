<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\DiagnosisReviewQueueResource\Pages;
use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\AuditLog;
use App\Models\Diagnosis;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Section 11 & 14: Agronomist / Vet Diagnosis Review Queue.
 * Shows farmer photo, top 3 candidates, clarifying questions transcript, and allows
 * Agronomists to Confirm or Override/Correct the AI diagnosis (feeding AI Accuracy metrics).
 */
final class DiagnosisReviewQueueResource extends Resource
{
    protected static ?string $model = Diagnosis::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationGroup = 'AI Agent & Guardrails';

    protected static ?string $navigationLabel = 'Agronomist Review Queue';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('AI Diagnosis Summary & Candidates')
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('final_diagnosis_name')
                        ->label('AI Top Diagnosis')
                        ->disabled(),
                    Forms\Components\TextInput::make('final_diagnosis_code')
                        ->label('Target Code')
                        ->disabled(),
                    Forms\Components\TextInput::make('confidence')
                        ->label('Confidence Score')
                        ->disabled(),
                    Forms\Components\TextInput::make('status')
                        ->disabled(),
                    Forms\Components\TextInput::make('escalation_reason')
                        ->columnSpan(2)
                        ->disabled(),
                    Forms\Components\KeyValue::make('candidates_json')
                        ->label('Ranked Vision Candidates JSON')
                        ->columnSpanFull()
                        ->disabled(),
                ]),

            Forms\Components\Section::make('Agronomist / Vet Verification & Override (Feeds AI Accuracy Metric)')
                ->columns(2)
                ->schema([
                    Forms\Components\Toggle::make('agronomist_confirmed')
                        ->label('Confirm AI Diagnosis is Accurate')
                        ->default(true),
                    Forms\Components\TextInput::make('agronomist_corrected_code')
                        ->label('Corrected Diagnosis Code (if overriding AI)')
                        ->placeholder('e.g. maize_stalk_borer'),
                    Forms\Components\Textarea::make('agronomist_notes')
                        ->label('Agronomist Notes / Corrected Advisory Message to Farmer')
                        ->rows(4)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Diagnosed At')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('crop.name_en')->label('Crop'),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('final_diagnosis_name')->label('AI Diagnosis')->searchable(),
                Tables\Columns\TextColumn::make('confidence')
                    ->formatStateUsing(fn ($state): string => round(((float) $state) * 100) . '%')
                    ->badge()
                    ->color(fn ($state): string => ((float) $state) >= 0.80 ? 'success' : (((float) $state) >= 0.55 ? 'warning' : 'danger')),
                Tables\Columns\TextColumn::make('questions_asked_count')->label('Qns Asked'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'escalated' => 'danger',
                        'reviewed' => 'success',
                        default => 'info',
                    }),
                Tables\Columns\IconColumn::make('agronomist_confirmed')
                    ->label('AI Accurate?')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'escalated' => 'Escalated (Needs Review)',
                        'advisory_issued' => 'Advisory Issued',
                        'reviewed' => 'Reviewed by Agronomist',
                    ]),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm_ai')
                    ->label('Approve AI')
                    ->icon('heroicon-o-check-Badge')
                    ->color('success')
                    ->visible(fn (Diagnosis $record): bool => $record->agronomist_confirmed === null)
                    ->action(function (Diagnosis $record): void {
                        $record->update([
                            'status' => 'reviewed',
                            'agronomist_confirmed' => true,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        AuditLog::query()->create([
                            'tenant_id' => $record->tenant_id,
                            'conversation_id' => $record->conversation_id,
                            'diagnosis_id' => $record->id,
                            'user_id' => auth()->id(),
                            'event_type' => 'agronomist_review_confirmed',
                            'created_at' => now(),
                        ]);

                        Notification::make()->title('Diagnosis confirmed as accurate.')->success()->send();
                    }),

                Tables\Actions\Action::make('override_ai')
                    ->label('Override / Send Correction')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('agronomist_corrected_code')
                            ->label('Corrected Target Code')
                            ->required(),
                        Forms\Components\Textarea::make('agronomist_notes')
                            ->label('Verified Agronomist Advisory to Send to Farmer')
                            ->required(),
                    ])
                    ->action(function (Diagnosis $record, array $data): void {
                        $record->update([
                            'status' => 'reviewed',
                            'agronomist_confirmed' => false,
                            'agronomist_corrected_code' => $data['agronomist_corrected_code'],
                            'agronomist_notes' => $data['agronomist_notes'],
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                        ]);

                        SendOutboundChannelMessageJob::dispatch(
                            $record->conversation_id,
                            "👨‍🌾 *Verified Agronomist Review Update:*\n" . $data['agronomist_notes'],
                            [],
                            'agronomist'
                        );

                        AuditLog::query()->create([
                            'tenant_id' => $record->tenant_id,
                            'conversation_id' => $record->conversation_id,
                            'diagnosis_id' => $record->id,
                            'user_id' => auth()->id(),
                            'event_type' => 'agronomist_review_overridden',
                            'metadata_json' => $data,
                            'created_at' => now(),
                        ]);

                        Notification::make()->title('Diagnosis overridden and verified update sent to farmer.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDiagnosisReviewQueue::route('/'),
        ];
    }
}
