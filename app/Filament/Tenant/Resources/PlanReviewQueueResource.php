<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources;

use App\Enums\PlanState;
use App\Filament\Tenant\Resources\PlanReviewQueueResource\Pages;
use App\Models\PlanReview;
use App\Services\Planning\PlanStateMachine;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class PlanReviewQueueResource extends Resource
{
    protected static ?string $model = PlanReview::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-magnifying-glass';

    protected static ?string $navigationGroup = 'Farm Planning & Benchmarks';

    protected static ?string $navigationLabel = 'Plan Agronomist Review Queue';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('farmPlan.title')->label('Farm Plan')->searchable(),
                Tables\Columns\TextColumn::make('farmPlan.viability_verdict')->label('Verdict')->badge(),
                Tables\Columns\TextColumn::make('farmPlan.verdict_label')->label('Data Label')->badge()->color('warning'),
                Tables\Columns\TextColumn::make('trigger_reason')->badge(),
                Tables\Columns\TextColumn::make('status')->badge(),
                Tables\Columns\TextColumn::make('created_at')->dateTime(),
            ])
            ->actions([
                Tables\Actions\Action::make('approve_plan')
                    ->label('Approve & Mark Verified')
                    ->color('success')
                    ->form([
                        Forms\Components\Textarea::make('agronomist_comments')->required(),
                    ])
                    ->action(function (PlanReview $record, array $data): void {
                        $record->update([
                            'status' => 'approved',
                            'reviewer_user_id' => auth()->id(),
                            'agronomist_comments' => $data['agronomist_comments'],
                            'reviewed_at' => now(),
                        ]);
                        $plan = $record->farmPlan;
                        if ($plan !== null) {
                            $plan->update(['verdict_label' => 'verified']);
                            if ($plan->state === PlanState::REVIEW_REQUESTED) {
                                app(PlanStateMachine::class)->transitionTo($plan, PlanState::PROJECTION_READY, 'agronomist_approved_plan');
                            }
                        }
                        Notification::make()->title('Farm plan reviewed and verified.')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlanReviews::route('/'),
        ];
    }
}
