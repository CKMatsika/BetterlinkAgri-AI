<?php

declare(strict_types=1);

namespace App\Services\Crm\Adapters;

use App\Contracts\CrmAdapterContract;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;

final class ZohoCrmAdapter implements CrmAdapterContract
{
    public function adapterKey(): string
    {
        return 'zoho';
    }

    public function pushLead(Lead $lead): array
    {
        $crmConfig = (array) ($lead->tenant?->crm_config ?? []);
        $accessToken = (string) ($crmConfig['zoho_access_token'] ?? '');

        $zohoRecord = [
            'Deal_Name' => "{$lead->need_summary} — {$lead->farmer?->full_name_encrypted}",
            'Amount' => (float) $lead->estimated_value,
            'Stage' => 'Qualification',
            'Lead_Source' => 'BetterlinkAgr AI WhatsApp',
            'Description' => 'Temperature: ' . $lead->temperature->value . ' | Score: ' . $lead->intent_score,
        ];

        if ($accessToken === '') {
            $extId = 'ZOHO-SIM-' . strtoupper(substr($lead->id, 0, 8));
            $lead->update(['crm_status' => 'synced', 'external_crm_id' => $extId]);

            return ['synced' => true, 'external_crm_id' => $extId];
        }

        $response = Http::withHeaders([
            'Authorization' => "Zoho-oauthtoken {$accessToken}",
        ])->timeout(15)->post('https://www.zohoapis.com/crm/v3/Deals', [
            'data' => [$zohoRecord],
        ]);

        if ($response->successful()) {
            $extId = 'ZOHO-' . (string) $response->json('data.0.details.id', substr($lead->id, 0, 8));
            $lead->update(['crm_status' => 'synced', 'external_crm_id' => $extId]);

            return ['synced' => true, 'external_crm_id' => $extId];
        }

        $lead->update(['crm_status' => 'failed']);

        return ['synced' => false, 'external_crm_id' => ''];
    }
}
