<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NaturalRegion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Farm extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'farmer_id',
        'tenant_id',
        'ward_id',
        'name',
        'natural_region',
        'total_area_ha',
        'cultivated_area_ha',
        'soil_type',
        'soil_ph',
        'is_pfumvudza_plot',
        'pfumvudza_plots_count',
        'crops_grown_json',
        'livestock_kept_json',
    ];

    protected function casts(): array
    {
        return [
            'natural_region' => NaturalRegion::class,
            'total_area_ha' => 'decimal:2',
            'cultivated_area_ha' => 'decimal:2',
            'soil_ph' => 'decimal:2',
            'is_pfumvudza_plot' => 'boolean',
            'pfumvudza_plots_count' => 'integer',
            'crops_grown_json' => 'array',
            'livestock_kept_json' => 'array',
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }
}
