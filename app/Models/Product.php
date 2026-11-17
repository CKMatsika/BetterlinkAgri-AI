<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'manufacturer_tenant_id',
        'sku',
        'name',
        'brand_name',
        'category',
        'active_ingredient',
        'mode_of_action_group',
        'registration_number',
        'registration_expiry',
        'hazard_colour_band',
        'is_restricted_use',
        'agronomic_fit_rank_score',
        'differentiation_notes',
        'pack_sizes_json',
        'ppe_requirements_json',
        'safety_warnings_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'registration_expiry' => 'date',
            'is_restricted_use' => 'boolean',
            'agronomic_fit_rank_score' => 'decimal:2',
            'pack_sizes_json' => 'array',
            'ppe_requirements_json' => 'array',
            'safety_warnings_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function isRegistrationActive(): bool
    {
        if (empty($this->registration_number)) {
            return false;
        }

        if ($this->registration_expiry !== null && $this->registration_expiry->isPast()) {
            return false;
        }

        return $this->is_active === true;
    }

    public function manufacturerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'manufacturer_tenant_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProductDocument::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(ProductTarget::class);
    }

    public function outletStocks(): HasMany
    {
        return $this->hasMany(OutletStock::class);
    }
}
