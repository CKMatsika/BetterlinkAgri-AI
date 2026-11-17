<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Meeting extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'conversation_id',
        'farmer_id',
        'rep_user_id',
        'meeting_type',
        'proposed_slots_json',
        'confirmed_starts_at',
        'confirmed_ends_at',
        'ics_file_path',
        'status',
        'visit_notes',
        'visit_photos_json',
    ];

    protected function casts(): array
    {
        return [
            'proposed_slots_json' => 'array',
            'confirmed_starts_at' => 'datetime',
            'confirmed_ends_at' => 'datetime',
            'visit_photos_json' => 'array',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function rep(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rep_user_id');
    }
}
