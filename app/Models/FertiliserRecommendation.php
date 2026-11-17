<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FertiliserRecommendation extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'crop_id',
        'product_id',
        'natural_region',
        'soil_type',
        'growth_stage',
        'nutrient_addressed',
        'fertiliser_type_name',
        'rate_kg_per_ha',
        'rate_grams_per_plant',
        'rate_grams_per_pfumvudza_station',
        'local_measure_equivalent',
        'placement_method',
        'timing_notes',
        'precautions_notes',
        'source_document_id',
    ];

    protected function casts(): array
    {
        return [
            'rate_kg_per_ha' => 'decimal:2',
            'rate_grams_per_plant' => 'decimal:2',
            'rate_grams_per_pfumvudza_station' => 'decimal:2',
        ];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
