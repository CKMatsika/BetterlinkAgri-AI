<?php

declare(strict_types=1);

namespace App\Services\Leads;

use App\DTOs\LeadQualificationData;
use App\Enums\CurrencyCode;
use App\Enums\LeadTemperature;
use App\Jobs\SyncLeadToExternalCrmJob;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Lead;
use App\Models\LeadActivity;

/**
 * Section 8: Lead Qualification & Opportunity Creation Service.
 *
 * Detects buying intent (quantity, timing, budget currency, credit need),
 * scores Cold / Warm / Hot with explicit stored reasons, and creates an Opportunity (Lead) record.
 */
final class LeadQualificationService
{
    public function evaluateBuyingIntent(
        Conversation $conversation,
        string $farmerMessageText,
        ?Diagnosis $diagnosis = null
    ): LeadQualificationData {
        $lower = strtolower($farmerMessageText);
        $score = 40;
        $reasons = [];

        // 1. Urgency & purchase window signals
        $purchaseWindow = 'within_14_days';
        if (preg_match('/\b(today|tomorrow|now|urgent|immediately|hold|nhasi|mangwana|khathesi)\b/i', $lower) === 1) {
            $score += 30;
            $purchaseWindow = 'immediate_24_48h';
            $reasons[] = 'urgent_purchase_window_24_48h';
        } elseif (preg_match('/\b(this week|svondo rino|iviki leli)\b/i', $lower) === 1) {
            $score += 20;
            $purchaseWindow = 'within_7_days';
            $reasons[] = 'purchase_window_this_week';
        } elseif (preg_match('/\b(next season|later|just checking)\b/i', $lower) === 1) {
            $score -= 15;
            $purchaseWindow = 'next_season';
            $reasons[] = 'long_term_or_browsing';
        }

        // 2. Explicit buying / stock / rep request signals
        if (preg_match('/\b(buy|price|stock|reserve|hold|outlet|rep|dealer|kutenga|mutengo)\b/i', $lower) === 1) {
            $score += 20;
            $reasons[] = 'explicit_buying_or_stock_signal';
        }

        // 3. Field size & commercial scale signal
        $areaHa = (float) ($diagnosis?->field_size_ha ?? $conversation->farmer?->farms()->first()?->cultivated_area_ha ?? 1.0);
        if ($areaHa >= 2.0) {
            $score += 15;
            $reasons[] = "commercial_scale_{$areaHa}ha";
        } elseif ($areaHa > 0) {
            $score += 5;
            $reasons[] = "known_field_area_{$areaHa}ha";
        }

        // 4. Credit or contract farming signal
        $needsCredit = preg_match('/\b(credit|loan|contract|chikwereti|input scheme)\b/i', $lower) === 1;
        if ($needsCredit) {
            $reasons[] = 'requested_credit_or_contract_terms';
        }

        // 5. Currency detection (USD vs ZiG)
        $currency = str_contains(strtoupper($farmerMessageText), 'ZIG')
            ? CurrencyCode::ZIG
            : CurrencyCode::USD;

        // 6. Build requested products basket & estimated value from diagnosis advisory
        $requestedProducts = [];
        $estimatedValue = 0.0;

        $advisory = $diagnosis?->advisory;
        if ($advisory !== null) {
            foreach ((array) ($advisory->products_json ?? []) as $prod) {
                $pack = $prod['pack_sizes'][0] ?? ['size' => 'Standard', 'price_usd' => 15.0, 'price_zig' => 382.50];
                $qty = max(1, (int) ceil($areaHa));
                $unitPrice = $currency === CurrencyCode::ZIG
                    ? (float) ($pack['price_zig'] ?? 382.50)
                    : (float) ($pack['price_usd'] ?? 15.0);

                $requestedProducts[] = [
                    'product_id' => $prod['product_id'] ?? null,
                    'sku' => $prod['sku'] ?? '',
                    'product_name' => $prod['product_name'] ?? 'Product',
                    'pack_size' => $pack['size'] ?? 'Standard',
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'currency' => $currency->value,
                ];
                $estimatedValue += ($qty * $unitPrice);
            }
        }

        $clampedScore = max(10, min(100, $score));
        $temperature = match (true) {
            $clampedScore >= 75 => LeadTemperature::HOT,
            $clampedScore >= 45 => LeadTemperature::WARM,
            default => LeadTemperature::COLD,
        };

        if ($reasons === []) {
            $reasons[] = 'diagnosed_agronomic_need';
        }

        return new LeadQualificationData(
            temperature: $temperature,
            intentScore: $clampedScore,
            scoreReasons: $reasons,
            purchaseWindow: $purchaseWindow,
            estimatedValue: round($estimatedValue, 2),
            currency: $currency,
            needsCreditOrContract: $needsCredit,
            requestedProducts: $requestedProducts,
        );
    }

    public function createOrUpdateOpportunity(
        Conversation $conversation,
        LeadQualificationData $qualification,
        ?Diagnosis $diagnosis = null,
        ?string $assignedUserId = null,
        ?string $assignedOutletId = null
    ): Lead {
        $areaHa = (float) ($diagnosis?->field_size_ha ?? $conversation->farmer?->farms()->first()?->cultivated_area_ha ?? 1.0);
        $needSummary = $diagnosis?->final_diagnosis_name
            ? "{$diagnosis->final_diagnosis_name} control programme"
            : 'Crop input & nutrition inquiry';

        $lead = Lead::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'farmer_id' => $conversation->farmer_id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'crop_id' => $diagnosis?->crop_id,
            'area_ha' => $areaHa,
            'need_summary' => $needSummary,
            'purchase_window' => $qualification->purchaseWindow,
            'temperature' => $qualification->temperature,
            'intent_score' => $qualification->intentScore,
            'score_reasons_json' => $qualification->scoreReasons,
            'requested_products_json' => $qualification->requestedProducts,
            'estimated_value' => $qualification->estimatedValue,
            'currency' => $qualification->currency,
            'needs_credit_or_contract' => $qualification->needsCreditOrContract,
            'assigned_user_id' => $assignedUserId,
            'assigned_outlet_id' => $assignedOutletId,
            'meeting_status' => 'none',
            'crm_status' => 'ready_for_crm',
            'status' => 'new',
            'sla_due_at' => now()->addMinutes($qualification->temperature->slaMinutes()),
        ]);

        LeadActivity::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'lead_id' => $lead->id,
            'user_id' => $assignedUserId,
            'activity_type' => 'lead_scored_and_created',
            'notes' => "Lead scored {$qualification->temperature->value} ({$qualification->intentScore}/100): " . implode(', ', $qualification->scoreReasons),
            'metadata_json' => $qualification->toArray(),
        ]);

        // Dispatch async CRM sync if tenant is in integrated mode
        if (($conversation->tenant?->crm_config['mode'] ?? 'standalone') === 'integrated') {
            SyncLeadToExternalCrmJob::dispatch($lead->id);
        }

        return $lead;
    }
}
