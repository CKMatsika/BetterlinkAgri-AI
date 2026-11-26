<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByMarketplace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StockSignal extends Model
{
    use HasUuids;
    use ScopedByMarketplace;

    protected $fillable = [
        'request_id',
        'organisation_id',
        'outlet_id',
        'product_id',
        'category',
        'district_id',
        'ward_id',
        'signal_type',
        'quantity_requested_scaled',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_requested_scaled' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }
}
