<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DeviceToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'owner_type',
        'owner_id',
        'organisation_id',
        'platform',
        'token_hash',
        'token_encrypted',
        'is_active',
        'last_seen_at',
    ];

    protected $hidden = [
        'token_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }
}
