<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByMarketplace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestRecipient extends Model
{
    use HasUuids;
    use ScopedByMarketplace;

    protected $fillable = [
        'request_id',
        'organisation_id',
        'outlet_id',
        'ring_number',
        'distance_band',
        'notified_at',
        'delivered_at',
        'opened_at',
        'responded_at',
        'response',
    ];

    protected function casts(): array
    {
        return [
            'ring_number' => 'integer',
            'notified_at' => 'datetime',
            'delivered_at' => 'datetime',
            'opened_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MarketplaceRequest::class, 'request_id');
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'organisation_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
