<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Contracts\CrmAdapterContract;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\Crm\Adapters\CsvExportImportAdapter;
use App\Services\Crm\Adapters\HubSpotCrmAdapter;
use App\Services\Crm\Adapters\SignedWebhookCrmAdapter;
use App\Services\Crm\Adapters\StandaloneDatabaseAdapter;
use App\Services\Crm\Adapters\ZohoCrmAdapter;

final class CrmManager
{
    /** @var array<string, CrmAdapterContract> */
    private array $adapters = [];

    public function __construct(
        StandaloneDatabaseAdapter $standalone,
        CsvExportImportAdapter $csv,
        SignedWebhookCrmAdapter $webhook,
        HubSpotCrmAdapter $hubspot,
        ZohoCrmAdapter $zoho
    ) {
        foreach ([$standalone, $csv, $webhook, $hubspot, $zoho] as $adapter) {
            $this->adapters[$adapter->adapterKey()] = $adapter;
        }
    }

    public function resolveAdapter(?string $adapterKey): CrmAdapterContract
    {
        $key = strtolower(trim((string) $adapterKey));

        return $this->adapters[$key] ?? $this->adapters['standalone'];
    }

    /**
     * Sync a Lead using the tenant's configured CRM adapter and log a LeadActivity.
     *
     * @return array{synced: bool, external_crm_id: string, adapter: string}
     */
    public function syncLead(Lead $lead): array
    {
        $lead->loadMissing(['tenant', 'farmer', 'crop']);
        $crmConfig = (array) ($lead->tenant?->crm_config ?? []);
        $adapterKey = (string) ($crmConfig['adapter'] ?? 'standalone');

        $adapter = $this->resolveAdapter($adapterKey);
        $result = $adapter->pushLead($lead);

        LeadActivity::withoutGlobalScopes()->create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'activity_type' => $result['synced'] ? 'crm_synced' : 'crm_sync_failed',
            'notes' => "CRM sync via '{$adapter->adapterKey()}' -> {$result['external_crm_id']}",
            'metadata_json' => $result,
        ]);

        return [
            'synced' => $result['synced'],
            'external_crm_id' => $result['external_crm_id'],
            'adapter' => $adapter->adapterKey(),
        ];
    }
}
