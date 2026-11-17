<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HandoffType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Handoff extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'diagnosis_id',
        'lead_id',
        'handoff_type',
        'assigned_user_id',
        'outlet_id',
        'priority',
        'reason',
        'context_summary_json',
        'status',
        'sla_due_at',
        'accepted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'handoff_type' => HandoffType::class,
            'context_summary_json' => 'array',
            'sla_due_at' => 'datetime',
            'accepted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }
}
