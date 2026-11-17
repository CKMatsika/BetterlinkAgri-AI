<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Programme extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'crop_id',
        'target_stage',
        'enrolment_rules_json',
        'stage_messages_json',
        'monthly_priority_question_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'enrolment_rules_json' => 'array',
            'stage_messages_json' => 'array',
            'monthly_priority_question_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function enrolments(): HasMany
    {
        return $this->hasMany(ProgrammeEnrolment::class);
    }
}
