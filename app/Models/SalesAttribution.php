<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesAttribution extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'sales_attribution';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'diagnosis_id',
        'lead_id',
        'reservation_id',
        'outlet_id',
        'product_id',
        'farmer_id',
        'quantity_sold',
        'total_amount',
        'currency',
        'is_first_ai_engaged_sale',
        'is_upsell_on_engaged_farmer',
        'attributed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity_sold' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'currency' => CurrencyCode::class,
            'is_first_ai_engaged_sale' => 'boolean',
            'is_upsell_on_engaged_farmer' => 'boolean',
            'attributed_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
