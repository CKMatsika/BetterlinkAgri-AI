<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\OutletStock;
use Illuminate\Console\Command;

final class SendStaleOutletStockRemindersCommand extends Command
{
    protected $signature = 'betterlink:remind-stale-outlets';

    protected $description = 'Send daily reminders to outlets whose stock data is older than the 48-hour freshness limit.';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('betterlink.outlets.stock_freshness_limit_hours', 48));

        $staleOutletIds = OutletStock::withoutGlobalScopes()
            ->where('last_updated_at', '<', $cutoff)
            ->distinct()
            ->pluck('outlet_id')
            ->all();

        $outlets = Outlet::withoutGlobalScopes()
            ->whereIn('id', $staleOutletIds)
            ->where('is_active', '=', true)
            ->get();

        foreach ($outlets as $outlet) {
            AuditLog::withoutGlobalScopes()->create([
                'tenant_id' => $outlet->tenant_id,
                'event_type' => 'stale_stock_reminder_sent',
                'metadata_json' => [
                    'outlet_id' => $outlet->id,
                    'outlet_name' => $outlet->name,
                    'whatsapp_number' => $outlet->whatsapp_number,
                ],
                'created_at' => now(),
            ]);
        }

        $this->info("Sent stale stock reminders to {$outlets->count()} outlets.");

        return self::SUCCESS;
    }
}
