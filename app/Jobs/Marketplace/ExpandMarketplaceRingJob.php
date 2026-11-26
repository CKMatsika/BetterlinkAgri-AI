<?php

declare(strict_types=1);

namespace App\Jobs\Marketplace;

use App\Models\MarketplaceRequest;
use App\Services\Marketplace\MarketplaceBroadcastService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpandMarketplaceRingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $requestId,
        public readonly int $ringNumber
    ) {
        $this->onQueue('marketplace-timers');
    }

    public function handle(MarketplaceBroadcastService $broadcastService): void
    {
        $request = MarketplaceRequest::withoutGlobalScopes()->find($this->requestId);
        if ($request === null) {
            return;
        }

        $broadcastService->handleRingTimeout($request, $this->ringNumber);
    }
}
