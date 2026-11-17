<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanProjection extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_plan_id',
        'currency',
        'capital_cost_minor',
        'working_capital_minor',
        'total_cost_minor',
        'total_revenue_minor',
        'gross_margin_minor',
        'net_margin_minor',
        'peak_funding_need_minor',
        'break_even_price_minor',
        'break_even_volume_scaled',
        'payback_period_days',
        'roi_pct_scaled',
        'monthly_cashflow_json',
        'cost_breakdown_json',
        'sensitivity_table_json',
    ];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'capital_cost_minor' => 'integer',
            'working_capital_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'total_revenue_minor' => 'integer',
            'gross_margin_minor' => 'integer',
            'net_margin_minor' => 'integer',
            'peak_funding_need_minor' => 'integer',
            'break_even_price_minor' => 'integer',
            'break_even_volume_scaled' => 'integer',
            'payback_period_days' => 'integer',
            'roi_pct_scaled' => 'integer',
            'monthly_cashflow_json' => 'array',
            'cost_breakdown_json' => 'array',
            'sensitivity_table_json' => 'array',
        ];
    }

    public function farmPlan(): BelongsTo
    {
        return $this->belongsTo(FarmPlan::class);
    }
}
