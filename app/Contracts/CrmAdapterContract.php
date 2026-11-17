<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\Lead;

interface CrmAdapterContract
{
    public function adapterKey(): string;

    /**
     * Push or export a qualified Lead to the tenant's CRM/ERP and return the external CRM ID.
     *
     * @return array{synced: bool, external_crm_id: string, metadata?: array<string, mixed>}
     */
    public function pushLead(Lead $lead): array;
}
