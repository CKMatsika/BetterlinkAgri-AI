<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Marketplace\DemandAggregationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

final class AggregateMarketplaceDemandCommand extends Command
{
    protected $signature = 'marketplace:aggregate-demand
        {--from= : Start date YYYY-MM-DD (defaults to 7 days ago)}
        {--to= : End date YYYY-MM-DD (defaults to today)}
        {--granularity=weekly : daily|weekly|monthly}';

    protected $description = 'Deterministically aggregate marketplace demand, unmet stock signals, and anonymised sales into demand_aggregates.';

    public function handle(DemandAggregationService $service): int
    {
        $from = $this->option('from') ? Carbon::parse((string) $this->option('from')) : now()->subDays(6);
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : now();
        $granularity = (string) ($this->option('granularity') ?: 'weekly');

        $counts = $service->aggregatePeriod($from, $to, $granularity);

        $this->info(sprintf(
            'Aggregated %s..%s (%s): area=%d, manufacturer=%d, retailer=%d',
            $from->toDateString(),
            $to->toDateString(),
            $granularity,
            $counts['area_rows'],
            $counts['manufacturer_rows'],
            $counts['retailer_rows']
        ));

        return self::SUCCESS;
    }
}
