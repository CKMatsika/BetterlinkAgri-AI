<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FarmerLocation extends Model
{
    use HasUuids;

    protected $fillable = [
        'farmer_id',
        'tenant_id',
        'ward_id',
        'latitude_encrypted',
        'longitude_encrypted',
        'latitude',
        'longitude',
        'accuracy_meters',
        'source',
        'is_approximate',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude_encrypted' => 'encrypted',
            'longitude_encrypted' => 'encrypted',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'accuracy_meters' => 'decimal:2',
            'is_approximate' => 'boolean',
            'captured_at' => 'datetime',
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
