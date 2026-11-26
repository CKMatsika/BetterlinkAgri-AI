<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinanceApplication extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'farmer_id',
        'bank_organisation_id',
        'farm_plan_id',
        'forward_quote_id',
        'consent_event_id',
        'requested_amount_minor',
        'approved_amount_minor',
        'currency',
        'purpose',
        'status',
        'plan_snapshot_json',
        'decision_notes',
        'reviewed_by_user_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_amount_minor' => 'integer',
            'approved_amount_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'plan_snapshot_json' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'bank_organisation_id');
    }

    public function farmPlan(): BelongsTo
    {
        return $this->belongsTo(FarmPlan::class);
    }

    public function forwardQuote(): BelongsTo
    {
        return $this->belongsTo(ForwardQuote::class);
    }

    public function consentEvent(): BelongsTo
    {
        return $this->belongsTo(ConsentEvent::class);
    }
}
