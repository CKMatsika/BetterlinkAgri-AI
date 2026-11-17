<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlanEnterprise extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name_en',
        'name_sn',
        'name_nd',
        'category',
        'default_unit',
        'verdict_rules_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'verdict_rules_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function questionBanks(): HasMany
    {
        return $this->hasMany(EnterpriseQuestionBank::class, 'enterprise_id')->orderBy('question_order');
    }

    public function farmPlans(): HasMany
    {
        return $this->hasMany(FarmPlan::class, 'enterprise_id');
    }
}
