<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Output commodity market prices (live broilers, dressed birds, maize, wheat, milk, etc.).
 * Explicitly NOT scoped by TenantScope; supports global national/regional prices and optional tenant_id overrides.
 */
class MarketPrice extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'commodity_code',
        'commodity_name',
        'region_or_district',
        'grade',
        'unit',
        'price_minor',
        'currency',
        'source',
        'effective_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'effective_date' => 'date',
        ];
    }
}
