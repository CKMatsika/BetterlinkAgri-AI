<?php

declare(strict_types=1);

namespace App\Jobs\Marketplace;

use App\Enums\MarketplaceRequestStatus;
use App\Models\MarketplaceRequest;
use App\Services\Marketplace\MarketplaceRequestStateMachine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ExpireMarketplaceRequestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $requestId
    ) {
        $this->onQueue('marketplace-timers');
    }

    public function handle(MarketplaceRequestStateMachine $stateMachine): void
    {
        $request = MarketplaceRequest::withoutGlobalScopes()->find($this->requestId);
        if ($request === null) {
            return;
        }

        $status = $request->status;
        if ($status === MarketplaceRequestStatus::UPSTREAM_ESCALATED) {
            $stateMachine->transitionTo($request, MarketplaceRequestStatus::UNFULFILLED, 'request_deadline_passed_unfulfilled');
            return;
        }

        if (in_array($status, [
            MarketplaceRequestStatus::BROADCAST_RING_1,
            MarketplaceRequestStatus::BROADCAST_RING_2,
            MarketplaceRequestStatus::BROADCAST_RING_3,
            MarketplaceRequestStatus::OFFERS_RECEIVED,
        ], true)) {
            $stateMachine->transitionTo($request, MarketplaceRequestStatus::EXPIRED, 'request_deadline_passed');
        }
    }
}
