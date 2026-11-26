<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Models\Concerns\ScopedByMarketplace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketplaceOrder extends Model
{
    use HasUuids;
    use ScopedByMarketplace;
    use SoftDeletes;

    protected $table = 'orders';

    protected $fillable = [
        'request_id',
        'offer_id',
        'farmer_id',
        'organisation_id',
        'outlet_id',
        'status',
        'total_amount_minor',
        'delivery_fee_minor',
        'currency',
        'fulfilment_type',
        'payment_method',
        'payment_status',
        'farmer_contact_revealed_at',
        'farmer_confirmed_at',
        'seller_confirmed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'total_amount_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'farmer_contact_revealed_at' => 'datetime',
            'farmer_confirmed_at' => 'datetime',
            'seller_confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MarketplaceRequest::class, 'request_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'organisation_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_id');
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class, 'order_id');
    }
}
