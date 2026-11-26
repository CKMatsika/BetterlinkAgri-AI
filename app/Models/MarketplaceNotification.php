<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MarketplaceNotification extends Model
{
    use HasUuids;

    protected $table = 'notifications';

    protected $fillable = [
        'recipient_type',
        'recipient_id',
        'organisation_id',
        'channel',
        'event_type',
        'title',
        'body',
        'payload_json',
        'status',
        'sent_at',
        'acknowledged_at',
        'failed_reason',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'sent_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }
}
