<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Crm\CrmManager;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncLeadToExternalCrmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $leadId
    ) {
        $this->onQueue('campaigns-low');
    }

    public function handle(TenantContext $tenantContext, CrmManager $crmManager): void
    {
        $lead = Lead::withoutGlobalScopes()->findOrFail($this->leadId);

        $tenantContext->runForTenant($lead->tenant_id, function () use ($lead, $crmManager): void {
            $crmManager->syncLead($lead);
        });
    }
}
