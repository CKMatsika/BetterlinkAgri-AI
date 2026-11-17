<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'campaign_type',
        'segment_filter_json',
        'whatsapp_template_name',
        'whatsapp_template_status',
        'body_en',
        'body_sn',
        'body_nd',
        'throttle_per_minute',
        'scheduled_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'segment_filter_json' => 'array',
            'throttle_per_minute' => 'integer',
            'scheduled_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }
}
