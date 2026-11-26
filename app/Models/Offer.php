<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Models\Concerns\ScopedByMarketplace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Offer extends Model
{
    use HasUuids;
    use ScopedByMarketplace;
    use SoftDeletes;

    protected $fillable = [
        'request_id',
        'organisation_id',
        'outlet_id',
        'submitted_by_user_id',
        'status',
        'unit_price_minor',
        'total_price_minor',
        'currency',
        'quantity_available_scaled',
        'fulfilment_type',
        'delivery_fee_minor',
        'valid_until',
        'rank_score_scaled',
        'rank_explanation_json',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'unit_price_minor' => 'integer',
            'total_price_minor' => 'integer',
            'quantity_available_scaled' => 'integer',
            'delivery_fee_minor' => 'integer',
            'valid_until' => 'datetime',
            'rank_score_scaled' => 'integer',
            'rank_explanation_json' => 'array',
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
