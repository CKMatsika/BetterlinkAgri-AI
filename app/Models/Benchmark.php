<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Benchmark extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'enterprise_code',
        'natural_region',
        'metric_key',
        'value_type',
        'value_scaled',
        'range_low_scaled',
        'range_high_scaled',
        'unit',
        'currency',
        'source_id',
        'effective_date',
        'reviewed_by',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'value_scaled' => 'integer',
            'range_low_scaled' => 'integer',
            'range_high_scaled' => 'integer',
            'effective_date' => 'date',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(BenchmarkSource::class, 'source_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
