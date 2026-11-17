<?php

declare(strict_types=1);

namespace App\Services\Outlets;

use App\Enums\CurrencyCode;
use App\Enums\LeadTemperature;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Farmer;
use App\Models\Lead;
use App\Models\Outlet;
use App\Models\Reservation;
use App\Models\Tenant;

final class ReservationService
{
    /**
     * Create a stock hold ("Hold this for me") and linked Lead with outlet SLA timer.
     *
     * @param list<array{product_id: string, sku: string, product_name: string, pack_size: string, quantity: int, price_usd: ?float, price_zig: ?float}> $basketItems
     */
    public function createReservationAndLead(
        Tenant $tenant,
        Conversation $conversation,
        Farmer $farmer,
        Outlet $outlet,
        array $basketItems,
        ?Diagnosis $diagnosis = null
    ): Reservation {
        $estimatedUsd = 0.0;
        foreach ($basketItems as $item) {
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $estimatedUsd += $qty * ((float) ($item['price_usd'] ?? 0.0));
        }

        $lead = Lead::query()->create([
            'tenant_id' => $tenant->id,
            'farmer_id' => $farmer->id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'crop_id' => $diagnosis?->crop_id,
            'area_ha' => $diagnosis?->field_size_ha ?? 1.0,
            'need_summary' => $diagnosis?->final_diagnosis_name ?? 'Product Stock Reservation',
            'purchase_window' => 'immediate_24_48h',
            'temperature' => LeadTemperature::HOT,
            'intent_score' => 90,
            'score_reasons_json' => ['farmer_requested_outlet_stock_hold', 'immediate_need'],
            'requested_products_json' => $basketItems,
            'estimated_value' => round($estimatedUsd, 2),
            'currency' => CurrencyCode::USD,
            'assigned_outlet_id' => $outlet->id,
            'crm_status' => 'ready_for_crm',
            'status' => 'new',
            'sla_due_at' => now()->addMinutes($tenant->outletSlaMinutes()),
        ]);

        $reservation = Reservation::query()->create([
            'tenant_id' => $tenant->id,
            'outlet_id' => $outlet->id,
            'farmer_id' => $farmer->id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'lead_id' => $lead->id,
            'basket_items_json' => $basketItems,
            'status' => 'pending',
            'sla_due_at' => now()->addMinutes($tenant->outletSlaMinutes()),
        ]);

        AuditLog::query()->create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'diagnosis_id' => $diagnosis?->id,
            'event_type' => 'reservation_event',
            'metadata_json' => [
                'action' => 'reservation_created',
                'reservation_id' => $reservation->id,
                'lead_id' => $lead->id,
                'outlet_id' => $outlet->id,
                'sla_due_at' => $reservation->sla_due_at?->toIso8601String(),
            ],
            'created_at' => now(),
        ]);

        return $reservation;
    }
}
