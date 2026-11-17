<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\Conversation;
use App\Models\Farmer;
use App\Models\Programme;
use App\Models\ProgrammeEnrolment;

/**
 * Section 9: Crop Programmes Engine.
 * Enrols farmers into stage-based crop programmes, delivers stage advisories,
 * and asks the monthly "main priority" question.
 */
final class ProgrammeEngineService
{
    public function enrolFarmer(
        Programme $programme,
        Farmer $farmer,
        ?string $initialStage = null
    ): ProgrammeEnrolment {
        $farm = $farmer->farms()->first();

        return ProgrammeEnrolment::withoutGlobalScopes()->updateOrCreate(
            [
                'programme_id' => $programme->id,
                'farmer_id' => $farmer->id,
            ],
            [
                'tenant_id' => $programme->tenant_id,
                'farm_id' => $farm?->id,
                'current_stage' => $initialStage ?? $programme->target_stage ?? 'basal_planting',
                'status' => 'active',
                'enrolled_at' => now(),
            ]
        );
    }

    public function sendStageAdvisoryAndPriorityQuestion(
        ProgrammeEnrolment $enrolment,
        Conversation $conversation
    ): string {
        $programme = $enrolment->programme;
        $stageMessages = (array) ($programme?->stage_messages_json ?? []);
        $currentStage = (string) ($enrolment->current_stage ?? 'basal_planting');

        $stageTip = (string) ($stageMessages[$currentStage]
            ?? "📋 *{$programme?->name} ({$currentStage}):* Ensure timely scouting and balanced crop nutrition this week.");

        $priorityQuestion = (string) ($programme?->monthly_priority_question_json['en']
            ?? 'What is your #1 priority on the farm this month? (1. Pest control, 2. Top-dressing fertiliser, 3. Weed control)');

        $fullMessage = "{$stageTip}\n\n*Monthly Check-In:* {$priorityQuestion}";

        SendOutboundChannelMessageJob::dispatch($conversation->id, $fullMessage);

        return $fullMessage;
    }
}
