<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Enums\LeadTemperature;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'farmer_id',
        'conversation_id',
        'diagnosis_id',
        'crop_id',
        'area_ha',
        'need_summary',
        'purchase_window',
        'temperature',
        'intent_score',
        'score_reasons_json',
        'requested_products_json',
        'estimated_value',
        'currency',
        'needs_credit_or_contract',
        'assigned_user_id',
        'assigned_outlet_id',
        'meeting_status',
        'crm_status',
        'external_crm_id',
        'status',
        'sla_due_at',
        'first_response_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'area_ha' => 'decimal:2',
            'temperature' => LeadTemperature::class,
            'intent_score' => 'integer',
            'score_reasons_json' => 'array',
            'requested_products_json' => 'array',
            'estimated_value' => 'decimal:2',
            'currency' => CurrencyCode::class,
            'needs_credit_or_contract' => 'boolean',
            'sla_due_at' => 'datetime',
            'first_response_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class);
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'assigned_outlet_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(Meeting::class);
    }
}
