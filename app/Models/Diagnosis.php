<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiagnosisCategory;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Diagnosis extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'farmer_id',
        'farm_id',
        'primary_attachment_id',
        'crop_id',
        'livestock_id',
        'ward_id',
        'category',
        'growth_stage',
        'field_size_ha',
        'pfumvudza_plots_count',
        'image_quality',
        'candidates_json',
        'missing_information_json',
        'final_diagnosis_name',
        'final_diagnosis_code',
        'confidence',
        'questions_asked_count',
        'status',
        'escalation_reason',
        'reviewed_by',
        'agronomist_confirmed',
        'agronomist_corrected_code',
        'agronomist_notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => DiagnosisCategory::class,
            'field_size_ha' => 'decimal:2',
            'pfumvudza_plots_count' => 'integer',
            'candidates_json' => 'array',
            'missing_information_json' => 'array',
            'confidence' => 'decimal:4',
            'questions_asked_count' => 'integer',
            'agronomist_confirmed' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class);
    }

    public function primaryAttachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class, 'primary_attachment_id');
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }

    public function livestock(): BelongsTo
    {
        return $this->belongsTo(Livestock::class);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(DiagnosisQuestion::class)->orderBy('question_order');
    }

    public function advisory(): HasOne
    {
        return $this->hasOne(Advisory::class);
    }
}
