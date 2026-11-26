<?php

declare(strict_types=1);

namespace App\Jobs\Marketplace;

use App\Services\Marketplace\DemandAggregationService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class AggregateDemandJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $periodStartDate,
        public readonly string $periodEndDate,
        public readonly string $granularity = 'weekly'
    ) {
        $this->onQueue('campaigns-low');
    }

    public function handle(DemandAggregationService $service): void
    {
        $service->aggregatePeriod(
            Carbon::parse($this->periodStartDate),
            Carbon::parse($this->periodEndDate),
            $this->granularity
        );
    }
}
