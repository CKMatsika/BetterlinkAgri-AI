<?php

declare(strict_types=1);

namespace App\Services\Crm\Adapters;

use App\Contracts\CrmAdapterContract;
use App\Models\Lead;
use Illuminate\Support\Facades\Http;

final class HubSpotCrmAdapter implements CrmAdapterContract
{
    public function adapterKey(): string
    {
        return 'hubspot';
    }

    public function pushLead(Lead $lead): array
    {
        $crmConfig = (array) ($lead->tenant?->crm_config ?? []);
        $accessToken = (string) ($crmConfig['hubspot_access_token'] ?? '');

        $properties = [
            'dealname' => "{$lead->need_summary} ({$lead->farmer?->full_name_encrypted})",
            'amount' => (string) $lead->estimated_value,
            'dealstage' => 'appointmentscheduled',
            'pipeline' => 'default',
            'description' => 'BetterlinkAgr AI Qualified Lead — Score: ' . $lead->intent_score . ' (' . $lead->temperature->value . ')',
        ];

        if ($accessToken === '') {
            $extId = 'HS-SIM-' . strtoupper(substr($lead->id, 0, 8));
            $lead->update(['crm_status' => 'synced', 'external_crm_id' => $extId]);

            return ['synced' => true, 'external_crm_id' => $extId];
        }

        $response = Http::withToken($accessToken)
            ->timeout(15)
            ->post('https://api.hubapi.com/crm/v3/objects/deals', [
                'properties' => $properties,
            ]);

        if ($response->successful()) {
            $extId = 'HS-' . (string) $response->json('id', substr($lead->id, 0, 8));
            $lead->update(['crm_status' => 'synced', 'external_crm_id' => $extId]);

            return ['synced' => true, 'external_crm_id' => $extId];
        }

        $lead->update(['crm_status' => 'failed']);

        return ['synced' => false, 'external_crm_id' => ''];
    }
}
