<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanInterviewAnswer extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_plan_id',
        'question_key',
        'answer_text',
        'parsed_value_json',
        'is_flagged_assumption',
        'flag_reason',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed_value_json' => 'array',
            'is_flagged_assumption' => 'boolean',
            'answered_at' => 'datetime',
        ];
    }

    public function farmPlan(): BelongsTo
    {
        return $this->belongsTo(FarmPlan::class);
    }
}
