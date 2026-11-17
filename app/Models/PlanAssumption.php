<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanAssumption extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_plan_id',
        'assumption_key',
        'label',
        'value_scaled',
        'unit',
        'currency',
        'source_type',
        'reference_id',
        'source_citation',
        'status',
        'is_outside_typical_range',
    ];

    protected function casts(): array
    {
        return [
            'value_scaled' => 'integer',
            'is_outside_typical_range' => 'boolean',
        ];
    }

    public function farmPlan(): BelongsTo
    {
        return $this->belongsTo(FarmPlan::class);
    }
}
