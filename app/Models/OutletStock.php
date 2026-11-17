<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Enums\StockQuantityBand;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletStock extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'outlet_stock';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'product_id',
        'pack_size',
        'quantity_band',
        'exact_quantity',
        'price',
        'currency',
        'source',
        'last_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_band' => StockQuantityBand::class,
            'exact_quantity' => 'integer',
            'price' => 'decimal:2',
            'currency' => CurrencyCode::class,
            'last_updated_at' => 'datetime',
        ];
    }

    public function isFresh(int $freshnessLimitHours = 48): bool
    {
        if ($this->last_updated_at === null) {
            return false;
        }

        return $this->last_updated_at->greaterThanOrEqualTo(now()->subHours($freshnessLimitHours));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
