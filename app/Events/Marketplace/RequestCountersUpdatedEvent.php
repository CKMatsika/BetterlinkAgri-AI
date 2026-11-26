<?php

declare(strict_types=1);

namespace App\Events\Marketplace;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time Reverb event pushed to the farmer's request channel for live counters
 * (sent to N, opened by N, offers N).
 */
final class RequestCountersUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param array{sent: int, delivered: int, opened: int, offers: int, declined: int, out_of_stock: int, current_ring: int, status: string} $counters
     */
    public function __construct(
        public readonly string $requestId,
        public readonly array $counters
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("marketplace.request.{$this->requestId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'marketplace.counters.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->requestId,
            'counters' => $this->counters,
        ];
    }
}
