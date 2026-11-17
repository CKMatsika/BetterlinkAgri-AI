<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationConsent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'farmer_id',
        'gps_capture_consent',
        'share_exact_with_outlet_consent',
        'consented_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'gps_capture_consent' => 'boolean',
            'share_exact_with_outlet_consent' => 'boolean',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function canCaptureGps(): bool
    {
        return $this->gps_capture_consent === true && $this->revoked_at === null;
    }

    public function canShareExactCoordinatesWithOutlet(): bool
    {
        return $this->share_exact_with_outlet_consent === true && $this->revoked_at === null;
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }
}
