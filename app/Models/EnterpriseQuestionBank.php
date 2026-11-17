<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LanguageCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EnterpriseQuestionBank extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'enterprise_id',
        'question_key',
        'question_order',
        'is_mandatory',
        'question_text_en',
        'question_text_sn',
        'question_text_nd',
        'options_json',
        'validation_rules_json',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'question_order' => 'integer',
            'is_mandatory' => 'boolean',
            'options_json' => 'array',
            'validation_rules_json' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function localizedQuestionText(LanguageCode $lang): string
    {
        return match ($lang) {
            LanguageCode::SHONA => $this->question_text_sn ?: $this->question_text_en,
            LanguageCode::NDEBELE => $this->question_text_nd ?: $this->question_text_en,
            LanguageCode::ENGLISH => $this->question_text_en,
        };
    }

    public function enterprise(): BelongsTo
    {
        return $this->belongsTo(PlanEnterprise::class, 'enterprise_id');
    }
}
