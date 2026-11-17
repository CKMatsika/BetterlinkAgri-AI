<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NaturalRegion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ward extends Model
{
    use HasUuids;

    protected $fillable = [
        'district_id',
        'ward_number',
        'name',
        'natural_region',
    ];

    protected function casts(): array
    {
        return [
            'ward_number' => 'integer',
            'natural_region' => NaturalRegion::class,
        ];
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function centroid(): HasOne
    {
        return $this->hasOne(WardCentroid::class);
    }
}
