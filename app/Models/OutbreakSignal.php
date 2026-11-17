<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OutbreakSignal extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'ward_id',
        'district_id',
        'crop_id',
        'target_code',
        'cases_count_7d',
        'avg_confidence',
        'severity',
        'alert_campaign_id',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'cases_count_7d' => 'integer',
            'avg_confidence' => 'decimal:4',
            'detected_at' => 'datetime',
        ];
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function crop(): BelongsTo
    {
        return $this->belongsTo(Crop::class);
    }
}
