<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Enums\CurrencyCode;
use App\Enums\LeadTemperature;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\User;

final class CreateLeadTool implements AiToolContract
{
    public function name(): string
    {
        return 'create_lead';
    }

    public function description(): string
    {
        return 'Qualify buying intent (cold/warm/hot with reasons) and create an opportunity/lead assigned to the nearest sales rep.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'temperature' => ['type' => 'string', 'enum' => ['cold', 'warm', 'hot']],
                'intent_score' => ['type' => 'integer'],
                'score_reasons' => ['type' => 'array', 'items' => ['type' => 'string']],
                'purchase_window' => ['type' => 'string'],
                'estimated_value' => ['type' => 'number'],
                'currency' => ['type' => 'string', 'enum' => ['USD', 'ZIG']],
                'needs_credit_or_contract' => ['type' => 'boolean'],
            ],
            'required' => ['temperature'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $temp = LeadTemperature::tryFrom((string) ($input['temperature'] ?? 'warm')) ?? LeadTemperature::WARM;
        $diagnosis = $conversation->diagnoses()->latest()->first();

        $rep = User::query()
            ->where('tenant_id', '=', $conversation->tenant_id)
            ->where('role', '=', UserRole::SALES_REP)
            ->where('is_available_for_handoff', '=', true)
            ->first();

        $lead = Lead::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'farmer_id' => $conversation->farmer_id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'crop_id' => $diagnosis?->crop_id,
            'area_ha' => $diagnosis?->field_size_ha ?? 1.0,
            'need_summary' => $diagnosis?->final_diagnosis_name ?? 'Agri-Input Inquiry',
            'purchase_window' => (string) ($input['purchase_window'] ?? 'within_14_days'),
            'temperature' => $temp,
            'intent_score' => (int) ($input['intent_score'] ?? ($temp === LeadTemperature::HOT ? 85 : 60)),
            'score_reasons_json' => (array) ($input['score_reasons'] ?? ['diagnosed_need']),
            'estimated_value' => (float) ($input['estimated_value'] ?? 0.0),
            'currency' => CurrencyCode::tryFrom((string) ($input['currency'] ?? 'USD')) ?? CurrencyCode::USD,
            'needs_credit_or_contract' => (bool) ($input['needs_credit_or_contract'] ?? false),
            'assigned_user_id' => $rep?->id,
            'crm_status' => 'ready_for_crm',
            'status' => 'new',
            'sla_due_at' => now()->addMinutes($temp->slaMinutes()),
        ]);

        return [
            'lead_id' => $lead->id,
            'temperature' => $lead->temperature->value,
            'assigned_rep_id' => $rep?->id,
            'sla_due_at' => $lead->sla_due_at?->toIso8601String(),
        ];
    }
}
