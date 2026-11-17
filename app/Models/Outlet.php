<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Outlet extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'outlet_type',
        'name',
        'address',
        'province_id',
        'district_id',
        'ward_id',
        'latitude',
        'longitude',
        'operating_hours_json',
        'phone',
        'whatsapp_number',
        'delivery_radius_km',
        'delivery_fee_amount',
        'delivery_fee_currency',
        'accepted_payments_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'operating_hours_json' => 'array',
            'delivery_radius_km' => 'decimal:2',
            'delivery_fee_amount' => 'decimal:2',
            'accepted_payments_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(OutletStock::class);
    }

    public function authorisations(): HasMany
    {
        return $this->hasMany(OutletProductAuthorisation::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}
