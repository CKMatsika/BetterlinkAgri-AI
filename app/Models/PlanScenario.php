<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanScenario extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_plan_id',
        'scenario_type',
        'currency',
        'total_cost_minor',
        'total_revenue_minor',
        'net_margin_minor',
        'roi_pct_scaled',
    ];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'total_cost_minor' => 'integer',
            'total_revenue_minor' => 'integer',
            'net_margin_minor' => 'integer',
            'roi_pct_scaled' => 'integer',
        ];
    }

    public function farmPlan(): BelongsTo
    {
        return $this->belongsTo(FarmPlan::class);
    }
}
