<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductTarget extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'crop_id',
        'livestock_id',
        'target_type',
        'target_code',
        'target_name_en',
        'target_name_sn',
        'target_name_nd',
        'dose_per_16l_knapsack_value',
        'dose_per_16l_knapsack_unit',
        'dose_per_ha_value',
        'dose_per_ha_unit',
        'water_volume_per_ha_litres',
        'mixing_order',
        'application_timing_notes',
        'reapplication_interval_days',
        'max_applications_per_season',
        'phi_days',
        'rei_hours',
        'source_document_id',
        'source_page_number',
    ];

    protected function casts(): array
    {
        return [
            'dose_per_16l_knapsack_value' => 'decimal:2',
            'dose_per_ha_value' => 'decimal:2',
            'water_volume_per_ha_litres' => 'decimal:2',
            'reapplication_interval_days' => 'integer',
            'max_applications_per_season' => 'integer',
            'phi_days' => 'integer',
            'rei_hours' => 'integer',
            'source_page_number' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function livestock(): BelongsTo
    {
        return $this->belongsTo(Livestock::class);
    }

    public function sourceDocument(): BelongsTo
    {
        return $this->belongsTo(ProductDocument::class, 'source_document_id');
    }
}
