<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Models\Concerns\ScopedByMarketplace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SaleEvent extends Model
{
    use HasUuids;
    use ScopedByMarketplace;

    protected $fillable = [
        'order_id',
        'organisation_id',
        'outlet_id',
        'product_id',
        'category',
        'district_id',
        'ward_id',
        'quantity_scaled',
        'total_amount_minor',
        'currency',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'quantity_scaled' => 'integer',
            'total_amount_minor' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
