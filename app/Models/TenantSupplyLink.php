<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TenantSupplyLink extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'manufacturer_tenant_id',
        'distributor_or_retailer_tenant_id',
        'approved_product_ids_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'approved_product_ids_json' => 'array',
        ];
    }

    public function manufacturerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'manufacturer_tenant_id');
    }

    public function partnerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'distributor_or_retailer_tenant_id');
    }
}
