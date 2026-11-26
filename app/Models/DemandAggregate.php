<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Models\Concerns\ScopedByMarketplace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DemandAggregate extends Model
{
    use HasUuids;
    use ScopedByMarketplace;

    protected $fillable = [
        'period_start',
        'period_end',
        'granularity',
        'province_id',
        'district_id',
        'ward_id',
        'product_id',
        'category',
        'organisation_id',
        'request_count',
        'fulfilled_count',
        'unmet_count',
        'contributing_farmers_count',
        'total_quantity_scaled',
        'total_sales_minor',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'currency' => CurrencyCode::class,
            'request_count' => 'integer',
            'fulfilled_count' => 'integer',
            'unmet_count' => 'integer',
            'contributing_farmers_count' => 'integer',
            'total_quantity_scaled' => 'integer',
            'total_sales_minor' => 'integer',
        ];
    }
}
