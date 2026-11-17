<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Actions\Diagnosis\EscalateToAgronomistAction;
use App\Contracts\AiToolContract;
use App\Enums\HandoffType;
use App\Models\Conversation;

final class HandoffToHumanTool implements AiToolContract
{
    public function __construct(
        private readonly EscalateToAgronomistAction $escalateAction
    ) {
    }

    public function name(): string
    {
        return 'handoff_to_human';
    }

    public function description(): string
    {
        return 'Escalate conversation to a human agronomist, veterinary officer, sales representative, or outlet manager.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'handoff_type' => ['type' => 'string', 'enum' => ['sales_rep', 'outlet_manager', 'agronomist', 'vet']],
                'reason' => ['type' => 'string'],
                'priority' => ['type' => 'string', 'enum' => ['normal', 'high', 'urgent_outbreak']],
            ],
            'required' => ['handoff_type', 'reason'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $type = HandoffType::tryFrom((string) $input['handoff_type']) ?? HandoffType::SALES_REP;
        $diagnosis = $conversation->diagnoses()->latest()->first();

        $handoff = $this->escalateAction->execute(
            conversation: $conversation,
            diagnosis: $diagnosis,
            reason: (string) $input['reason'],
            priority: (string) ($input['priority'] ?? 'high'),
            handoffType: $type
        );

        return [
            'handoff_id' => $handoff->id,
            'handoff_type' => $handoff->handoff_type->value,
            'status' => $handoff->status,
            'sla_due_at' => $handoff->sla_due_at?->toIso8601String(),
        ];
    }
}
