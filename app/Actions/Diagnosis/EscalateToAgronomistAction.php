<?php

declare(strict_types=1);

namespace App\Actions\Diagnosis;

use App\Enums\HandoffType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Handoff;
use App\Models\User;

final class EscalateToAgronomistAction
{
    public function execute(
        Conversation $conversation,
        ?Diagnosis $diagnosis,
        string $reason,
        string $priority = 'high',
        HandoffType $handoffType = HandoffType::AGRONOMIST
    ): Handoff {
        $assignedSpecialist = User::query()
            ->where('tenant_id', '=', $conversation->tenant_id)
            ->where('role', '=', UserRole::AGRONOMIST)
            ->where('is_available_for_handoff', '=', true)
            ->first();

        $slaMinutes = (int) config('betterlink.slas.agronomist_escalation_sla_minutes', 60);

        if ($diagnosis !== null) {
            $diagnosis->update([
                'status' => 'escalated',
                'escalation_reason' => $reason,
            ]);
        }

        $handoff = Handoff::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'handoff_type' => $handoffType,
            'assigned_user_id' => $assignedSpecialist?->id,
            'priority' => $priority,
            'reason' => $reason,
            'context_summary_json' => [
                'farmer_id' => $conversation->farmer_id,
                'context_chip' => $conversation->context_chip,
                'diagnosis_category' => $diagnosis?->category->value,
                'candidates' => $diagnosis?->candidates_json,
                'confidence' => $diagnosis?->confidence,
                'questions_asked' => $diagnosis?->questions()->get()->toArray(),
            ],
            'status' => 'pending',
            'sla_due_at' => now()->addMinutes($slaMinutes),
        ]);

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'user_id' => $assignedSpecialist?->id,
            'event_type' => 'agronomist_escalation',
            'metadata_json' => [
                'handoff_id' => $handoff->id,
                'reason' => $reason,
                'priority' => $priority,
            ],
            'created_at' => now(),
        ]);

        return $handoff;
    }
}
