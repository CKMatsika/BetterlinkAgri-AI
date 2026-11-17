<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Advisory extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'diagnosis_id',
        'advisory_type',
        'steps_json',
        'products_json',
        'rates_json',
        'weather_window_json',
        'safety_warnings_text',
        'rendered_message_text',
        'guardrail_passed',
        'farmer_thumbs_up',
        'farmer_rating',
        'farmer_feedback_text',
    ];

    protected function casts(): array
    {
        return [
            'steps_json' => 'array',
            'products_json' => 'array',
            'rates_json' => 'array',
            'weather_window_json' => 'array',
            'guardrail_passed' => 'boolean',
            'farmer_thumbs_up' => 'boolean',
            'farmer_rating' => 'integer',
        ];
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(Diagnosis::class);
    }
}
