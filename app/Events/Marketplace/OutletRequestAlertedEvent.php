<?php

declare(strict_types=1);

namespace App\Events\Marketplace;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Real-time Reverb event pushed to a notified retailer outlet's private channel.
 * Intentionally excludes exact farmer location and phone.
 */
final class OutletRequestAlertedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param list<array{item_name: string, quantity_scaled: int, unit: string}> $items
     */
    public function __construct(
        public readonly string $outletId,
        public readonly string $organisationId,
        public readonly string $requestId,
        public readonly string $recipientId,
        public readonly int $ringNumber,
        public readonly string $distanceBand,
        public readonly array $items,
        public readonly ?string $neededByDate,
        public readonly ?string $expiresAt
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
        return 'marketplace.request.alerted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'request_id' => $this->requestId,
            'recipient_id' => $this->recipientId,
            'outlet_id' => $this->outletId,
            'ring_number' => $this->ringNumber,
            'distance_band' => $this->distanceBand,
            'items' => $this->items,
            'needed_by_date' => $this->neededByDate,
            'expires_at' => $this->expiresAt,
        ];
    }
}
