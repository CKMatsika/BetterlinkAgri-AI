<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlanRequirement extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'farm_plan_id',
        'requirement_type',
        'item_code',
        'item_name',
        'quantity_scaled',
        'unit',
        'needed_by_date',
        'lead_time_days',
        'activation_at',
        'status',
        'farmer_confirmed_activation_at',
        'marketplace_request_id',
        'booking_id',
        'forward_quote_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity_scaled' => 'integer',
            'needed_by_date' => 'date',
            'lead_time_days' => 'integer',
            'activation_at' => 'datetime',
            'farmer_confirmed_activation_at' => 'datetime',
        ];
    }

    public function farmPlan(): BelongsTo
    {
        return $this->belongsTo(FarmPlan::class);
    }
}
