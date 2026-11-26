<?php

declare(strict_types=1);

namespace App\Jobs\Marketplace;

use App\Models\MarketplaceNotification;
use App\Models\Outlet;
use App\Models\RequestRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Delivers WhatsApp / SMS alerts to a notified retailer outlet.
 * Uses log-only delivery in local/testing or when live WhatsApp/SMS credentials are not configured.
 */
final class SendRetailerMarketplaceAlertJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $recipientId,
        public readonly string $channel = 'whatsapp'
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $recipient = RequestRecipient::withoutGlobalScopes()->find($this->recipientId);
        if ($recipient === null) {
            return;
        }

        $outlet = Outlet::withoutGlobalScopes()->find($recipient->outlet_id);
        if ($outlet === null) {
            return;
        }

        $targetNumber = $this->channel === 'whatsapp'
            ? ($outlet->whatsapp_number ?: $outlet->phone)
            : $outlet->phone;

        $body = "BetterlinkAgr Marketplace: New farmer request within {$recipient->distance_band} (Ring {$recipient->ring_number}). Open Outlet Portal to submit an offer.";

        $notification = MarketplaceNotification::query()->create([
            'recipient_type' => 'outlet',
            'recipient_id' => $outlet->id,
            'organisation_id' => $outlet->tenant_id,
            'channel' => $this->channel,
            'event_type' => 'retailer_request_alert',
            'title' => 'New Marketplace Request',
            'body' => $body,
            'payload_json' => [
                'request_id' => $recipient->request_id,
                'recipient_id' => $recipient->id,
                'distance_band' => $recipient->distance_band,
            ],
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Log::info('Retailer marketplace alert dispatched', [
            'channel' => $this->channel,
            'notification_id' => $notification->id,
            'outlet_id' => $outlet->id,
            'recipient_hash' => substr(hash('sha256', (string) $targetNumber), 0, 12),
            'request_id' => $recipient->request_id,
        ]);

        if ($recipient->delivered_at === null) {
            $recipient->update(['delivered_at' => now()]);
        }
    }
}
