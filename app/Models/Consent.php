<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consent extends Model
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'farmer_id',
        'channel',
        'status',
        'data_processing_consent',
        'marketing_consent',
        'policy_version',
        'opt_out_keyword_used',
        'consented_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'data_processing_consent' => 'boolean',
            'marketing_consent' => 'boolean',
            'consented_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isOptedIn(): bool
    {
        return $this->status === 'opted_in' && $this->data_processing_consent === true && $this->revoked_at === null;
    }

    public function isOptedOut(): bool
    {
        return $this->status === 'opted_out' || $this->revoked_at !== null;
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }
}
