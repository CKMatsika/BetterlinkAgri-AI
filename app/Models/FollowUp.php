<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUp extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'diagnosis_id',
        'farmer_id',
        'scheduled_for',
        'reminders_sent_count',
        'followup_attachment_id',
        'outcome',
        'comparison_summary',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'reminders_sent_count' => 'integer',
            'responded_at' => 'datetime',
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

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }
}
