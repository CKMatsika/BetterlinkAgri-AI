<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ForwardQuote extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'farmer_id',
        'offtaker_organisation_id',
        'farm_plan_id',
        'plan_requirement_id',
        'commodity_code',
        'commodity_name',
        'grade',
        'quantity_scaled',
        'unit',
        'quoted_unit_price_minor',
        'total_value_minor',
        'currency',
        'delivery_window_start',
        'delivery_window_end',
        'status',
        'valid_until',
        'farmer_contact_revealed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity_scaled' => 'integer',
            'quoted_unit_price_minor' => 'integer',
            'total_value_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'delivery_window_start' => 'date',
            'delivery_window_end' => 'date',
            'valid_until' => 'datetime',
            'farmer_contact_revealed_at' => 'datetime',
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function offtaker(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'offtaker_organisation_id');
    }

    public function planRequirement(): BelongsTo
    {
        return $this->belongsTo(PlanRequirement::class);
    }
}
