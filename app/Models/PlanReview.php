<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanReview extends Model
{
    use HasUuids;

    protected $fillable = [
        'farm_plan_id',
        'reviewer_user_id',
        'trigger_reason',
        'status',
        'agronomist_comments',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function farmPlan(): BelongsTo
    {
        return $this->belongsTo(FarmPlan::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_user_id');
    }
}
