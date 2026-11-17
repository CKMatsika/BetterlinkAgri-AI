<?php

declare(strict_types=1);

namespace App\Services\Crm\Adapters;

use App\Contracts\CrmAdapterContract;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;

final class SignedWebhookCrmAdapter implements CrmAdapterContract
{
    public function adapterKey(): string
    {
        return 'webhook';
    }

    public function pushLead(Lead $lead): array
    {
        $crmConfig = (array) ($lead->tenant?->crm_config ?? []);
        $webhookUrl = (string) ($crmConfig['webhook_url'] ?? '');
        $secret = (string) ($crmConfig['webhook_secret'] ?? config('services.crm.webhook_secret', 'betterlink-crm-secret'));

        $payload = [
            'event' => 'lead.qualified',
            'lead_id' => $lead->id,
            'tenant_id' => $lead->tenant_id,
            'farmer' => [
                'id' => $lead->farmer_id,
                'name' => $lead->farmer?->full_name_encrypted,
                'phone' => $lead->farmer?->phone_encrypted,
            ],
            'crop' => $lead->crop?->code,
            'area_ha' => (float) $lead->area_ha,
            'need_summary' => $lead->need_summary,
            'temperature' => $lead->temperature->value,
            'intent_score' => $lead->intent_score,
            'reasons' => $lead->score_reasons_json,
            'requested_products' => $lead->requested_products_json,
            'estimated_value' => (float) $lead->estimated_value,
            'currency' => $lead->currency->value,
            'purchase_window' => $lead->purchase_window,
            'timestamp' => now()->toIso8601String(),
        ];

        $jsonBody = (string) json_encode($payload);
        $signature = hash_hmac('sha256', $jsonBody, $secret);

        if ($webhookUrl === '') {
            $extId = 'WH-LOCAL-' . strtoupper(substr($lead->id, 0, 8));
            $lead->update(['crm_status' => 'synced', 'external_crm_id' => $extId]);

            return [
                'synced' => true,
                'external_crm_id' => $extId,
                'metadata' => ['signature' => $signature],
            ];
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Betterlink-Signature-256' => "sha256={$signature}",
        ])->timeout(15)->withBody($jsonBody, 'application/json')->post($webhookUrl);

        if ($response->successful()) {
            $extId = (string) ($response->json('external_id') ?: ('WH-' . strtoupper(substr($lead->id, 0, 8))));
            $lead->update(['crm_status' => 'synced', 'external_crm_id' => $extId]);

            return ['synced' => true, 'external_crm_id' => $extId];
        }

        $lead->update(['crm_status' => 'failed']);

        return ['synced' => false, 'external_crm_id' => ''];
    }
}
