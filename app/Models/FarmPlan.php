<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Enums\LanguageCode;
use App\Enums\PlanState;
use App\Enums\ViabilityVerdict;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FarmPlan extends Model
{
    use HasUuids;
    use SoftDeletes;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'version' => 1,
        'state' => 'INTERVIEW',
        'language' => 'en',
        'currency' => 'USD',
        'exchange_rate_scaled' => 255000,
        'inflation_pct_scaled' => 0,
    ];

    protected $fillable = [
        'tenant_id',
        'farmer_id',
        'enterprise_id',
        'ward_id',
        'parent_plan_id',
        'version',
        'title',
        'state',
        'language',
        'scale_value',
        'scale_unit',
        'planned_start_date',
        'currency',
        'exchange_rate_scaled',
        'inflation_pct_scaled',
        'viability_verdict',
        'verdict_label',
        'verdict_reasons_json',
        'verdict_conditions_json',
        'metadata_json',
        'accepted_at',
        'state_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'state' => PlanState::class,
            'language' => LanguageCode::class,
            'scale_value' => 'decimal:2',
            'planned_start_date' => 'date',
            'currency' => CurrencyCode::class,
            'exchange_rate_scaled' => 'integer',
            'inflation_pct_scaled' => 'integer',
            'viability_verdict' => ViabilityVerdict::class,
            'verdict_reasons_json' => 'array',
            'verdict_conditions_json' => 'array',
            'metadata_json' => 'array',
            'accepted_at' => 'datetime',
            'state_updated_at' => 'datetime',
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(PlanEnterprise::class, 'enterprise_id');
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function interviewAnswers(): HasMany
    {
        return $this->hasMany(PlanInterviewAnswer::class);
    }

    public function assumptions(): HasMany
    {
        return $this->hasMany(PlanAssumption::class);
    }

    public function projection(): HasOne
    {
        return $this->hasOne(PlanProjection::class);
    }

    public function scenarios(): HasMany
    {
        return $this->hasMany(PlanScenario::class);
    }

    public function riskFlags(): HasMany
    {
        return $this->hasMany(PlanRiskFlag::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(PlanChecklistItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PlanReview::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(PlanRequirement::class);
    }
}
