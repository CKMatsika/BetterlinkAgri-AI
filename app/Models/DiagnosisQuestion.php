<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiagnosisQuestion extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'diagnosis_id',
        'question_order',
        'question_key',
        'question_text',
        'options_json',
        'farmer_answer',
        'confidence_before',
        'confidence_after',
        'asked_at',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'question_order' => 'integer',
            'options_json' => 'array',
            'confidence_before' => 'decimal:4',
            'confidence_after' => 'decimal:4',
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class);
    }
}
