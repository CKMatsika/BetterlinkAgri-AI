<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutletProductAuthorisation extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'outlet_product_authorisation';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'product_id',
        'authorised_by_tenant_id',
        'is_authorised',
    ];

    protected function casts(): array
    {
        return [
            'is_authorised' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
