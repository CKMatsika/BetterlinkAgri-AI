<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceProvider extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'service_type',
        'province_id',
        'district_id',
        'base_latitude',
        'base_longitude',
        'coverage_radius_km',
        'unit_rate_minor',
        'rate_unit',
        'currency',
        'is_active',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'base_latitude' => 'decimal:7',
            'base_longitude' => 'decimal:7',
            'coverage_radius_km' => 'integer',
            'unit_rate_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'is_active' => 'boolean',
            'is_verified' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(ServiceBooking::class);
    }
}
