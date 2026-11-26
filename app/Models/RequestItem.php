<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'request_id',
        'product_id',
        'category',
        'item_name',
        'quantity_scaled',
        'unit',
        'pack_size',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_scaled' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MarketplaceRequest::class, 'request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
