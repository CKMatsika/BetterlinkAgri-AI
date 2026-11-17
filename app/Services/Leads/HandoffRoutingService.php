<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\DTOs\RichMessageCardData;
use App\Enums\HandoffType;
use App\Enums\RichCardType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Handoff;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;

/**
 * Section 8: Auto-Assignment, Handoff Summary Card, Deep-Linking & Rep Accept/Reject/Reassign Service.
 */
final class HandoffRoutingService
{
    /**
     * Route a qualified Lead and Conversation to the nearest available Sales Rep by District/Ward.
     *
     * @return array{handoff: Handoff, summary_card: RichMessageCardData, open_opportunity_url: string}
     */
    public function routeLeadToNearestRep(
        Conversation $conversation,
        Lead $lead,
        ?Diagnosis $diagnosis = null,
        string $reason = 'Qualified farmer lead ready for follow-up'
    ): array {
        $farmer = $conversation->farmer;
        $districtId = $diagnosis?->ward?->district_id ?? $farmer?->ward?->district_id;

        // 1. Match available Sales Rep in the farmer's district, or fallback to any available tenant Sales Rep
        $rep = User::query()
            ->where('tenant_id', '=', $conversation->tenant_id)
            ->where('role', '=', UserRole::SALES_REP)
            ->where('is_available_for_handoff', '=', true)
            ->when($districtId !== null, fn ($q) => $q->orderByRaw('CASE WHEN district_id = ? THEN 0 ELSE 1 END', [$districtId]))
            ->first();

        if ($rep !== null && $lead->assigned_user_id === null) {
            $lead->update(['assigned_user_id' => $rep->id]);
        }

        $tenantSlug = $conversation->tenant?->slug ?? 'default';
        $openOpportunityUrl = rtrim((string) config('app.url', 'http://localhost:8000'), '/')
            . "/admin/{$tenantSlug}/leads/{$lead->id}/edit";

        $contextSummary = [
            'farmer_name' => $farmer?->full_name_encrypted ?? 'Farmer',
            'farmer_phone' => $farmer?->phone_encrypted ?? $conversation->external_thread_id,
            'ward' => $diagnosis?->ward?->name ?? $farmer?->ward?->name ?? 'Zimbabwe',
            'crop' => $diagnosis?->crop?->name_en ?? 'Maize',
            'area_ha' => (float) ($lead->area_ha ?? 1.0),
            'problem_and_diagnosis' => $diagnosis?->final_diagnosis_name ?? $lead->need_summary,
            'confidence_pct' => $diagnosis !== null ? (int) round(((float) $diagnosis->confidence) * 100) : null,
            'recommended_products' => $lead->requested_products_json ?? [],
            'lead_temperature' => $lead->temperature->value,
            'intent_score' => $lead->intent_score,
            'intent_reasons' => $lead->score_reasons_json ?? [],
            'purchase_window' => $lead->purchase_window,
            'estimated_value' => "{$lead->currency->value} {$lead->estimated_value}",
            'open_opportunity_url' => $openOpportunityUrl,
        ];

        $handoff = Handoff::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'lead_id' => $lead->id,
            'handoff_type' => HandoffType::SALES_REP,
            'assigned_user_id' => $rep?->id,
            'outlet_id' => $lead->assigned_outlet_id,
            'priority' => $lead->temperature->value === 'hot' ? 'high' : 'normal',
            'reason' => $reason,
            'context_summary_json' => $contextSummary,
            'status' => 'pending',
            'sla_due_at' => $lead->sla_due_at ?? now()->addMinutes(120),
        ]);

        $summaryCard = new RichMessageCardData(
            cardType: RichCardType::HANDOFF_SUMMARY,
            title: "Lead Handoff: {$contextSummary['problem_and_diagnosis']} ({$lead->temperature->value})",
            contextChip: $conversation->context_chip ?? 'Rep Handoff',
            payload: $contextSummary
        );

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'user_id' => $rep?->id,
            'event_type' => 'lead_handoff_routed',
            'metadata_json' => [
                'handoff_id' => $handoff->id,
                'lead_id' => $lead->id,
                'assigned_rep_id' => $rep?->id,
                'open_opportunity_url' => $openOpportunityUrl,
            ],
            'created_at' => now(),
        ]);

        return [
            'handoff' => $handoff,
            'summary_card' => $summaryCard,
            'open_opportunity_url' => $openOpportunityUrl,
        ];
    }

    public function acceptHandoff(Handoff $handoff, User $rep): Handoff
    {
        $handoff->update([
            'assigned_user_id' => $rep->id,
            'status' => 'accepted',
            'accepted_at' => now(),
        ]);

        if ($handoff->lead !== null) {
            $handoff->lead->update([
                'assigned_user_id' => $rep->id,
                'status' => 'accepted',
                'first_response_at' => $handoff->lead->first_response_at ?? now(),
            ]);

            LeadActivity::query()->create([
                'tenant_id' => $handoff->tenant_id,
                'lead_id' => $handoff->lead_id,
                'user_id' => $rep->id,
                'activity_type' => 'rep_accepted_handoff',
                'notes' => "{$rep->name} accepted the lead handoff.",
            ]);
        }

        return $handoff;
    }

    public function reassignHandoff(Handoff $handoff, User $newRep, string $reason): Handoff
    {
        $handoff->update([
            'assigned_user_id' => $newRep->id,
            'status' => 'reassigned',
        ]);

        if ($handoff->lead !== null) {
            $handoff->lead->update(['assigned_user_id' => $newRep->id]);

            LeadActivity::query()->create([
                'tenant_id' => $handoff->tenant_id,
                'lead_id' => $handoff->lead_id,
                'user_id' => $newRep->id,
                'activity_type' => 'rep_reassigned',
                'notes' => "Reassigned to {$newRep->name}: {$reason}",
            ]);
        }

        return $handoff;
    }
}
