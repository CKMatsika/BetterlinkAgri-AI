<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletUser extends Model
{
    use HasUuids;

    protected $fillable = [
        'outlet_id',
        'user_id',
        'role',
        'receives_whatsapp_alerts',
    ];

    protected function casts(): array
    {
        return [
            'receives_whatsapp_alerts' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
