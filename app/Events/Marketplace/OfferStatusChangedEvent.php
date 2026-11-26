<?php

declare(strict_types=1);

namespace App\Events\Marketplace;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time Reverb event pushed to a retailer outlet when their own offer is accepted or closed.
 */
final class OfferStatusChangedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $outletId,
        public readonly string $requestId,
        public readonly string $offerId,
        public readonly string $status
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("marketplace.outlet.{$this->outletId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'marketplace.offer.status_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->requestId,
            'offer_id' => $this->offerId,
            'status' => $this->status,
        ];
    }
}
