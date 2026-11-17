<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOutlet;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Reservation extends Model
{
    use BelongsToOutlet;
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'farmer_id',
        'conversation_id',
        'diagnosis_id',
        'lead_id',
        'basket_items_json',
        'status',
        'rejection_reason',
        'alternative_proposal_text',
        'sla_due_at',
        'confirmed_at',
        'pickup_reminder_at',
        'fulfilled_at',
    ];

    protected function casts(): array
    {
        return [
            'basket_items_json' => 'array',
            'sla_due_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'pickup_reminder_at' => 'datetime',
            'fulfilled_at' => 'datetime',
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

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
