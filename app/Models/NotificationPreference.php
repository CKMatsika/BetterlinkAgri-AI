<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'organisation_id',
        'preferred_channels_json',
        'fallback_timeout_seconds',
        'quiet_hours_json',
        'is_muted',
    ];

    protected function casts(): array
    {
        return [
            'preferred_channels_json' => 'array',
            'fallback_timeout_seconds' => 'integer',
            'quiet_hours_json' => 'array',
            'is_muted' => 'boolean',
        ];
    }
}
