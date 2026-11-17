<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeatherSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'ward_id',
        'fetched_at',
        'valid_until',
        'temp_celsius',
        'humidity_pct',
        'wind_speed_kmh',
        'rain_probability_pct',
        'is_safe_to_spray',
        'spray_window_summary',
        'forecast_json',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'valid_until' => 'datetime',
            'temp_celsius' => 'decimal:2',
            'humidity_pct' => 'integer',
            'wind_speed_kmh' => 'decimal:2',
            'rain_probability_pct' => 'integer',
            'is_safe_to_spray' => 'boolean',
            'forecast_json' => 'array',
        ];
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }
}
