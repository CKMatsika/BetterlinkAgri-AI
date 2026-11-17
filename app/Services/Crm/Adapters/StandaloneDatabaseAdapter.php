<?php

declare(strict_types=1);

namespace App\Services\Crm\Adapters;

use App\Contracts\CrmAdapterContract;
use App\Models\Lead;

final class StandaloneDatabaseAdapter implements CrmAdapterContract
{
    public function adapterKey(): string
    {
        return 'standalone';
    }

    public function pushLead(Lead $lead): array
    {
        $externalId = 'LOCAL-' . strtoupper(substr($lead->id, 0, 8));
        $lead->update([
            'crm_status' => 'synced',
            'external_crm_id' => $externalId,
        ]);

        return [
            'synced' => true,
            'external_crm_id' => $externalId,
        ];
    }
}
