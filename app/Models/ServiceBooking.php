<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ServiceBooking extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'farmer_id',
        'service_provider_id',
        'organisation_id',
        'farm_plan_id',
        'plan_requirement_id',
        'service_type',
        'status',
        'scheduled_date',
        'quantity_scaled',
        'unit',
        'quoted_amount_minor',
        'currency',
        'province_id',
        'district_id',
        'approx_latitude',
        'approx_longitude',
        'farmer_contact_revealed_at',
        'completed_at',
        'notes',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_date' => 'date',
            'quantity_scaled' => 'integer',
            'quoted_amount_minor' => 'integer',
            'currency' => CurrencyCode::class,
            'approx_latitude' => 'decimal:7',
            'approx_longitude' => 'decimal:7',
            'farmer_contact_revealed_at' => 'datetime',
            'completed_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function serviceProvider(): BelongsTo
    {
        return $this->belongsTo(ServiceProvider::class);
    }

    public function planRequirement(): BelongsTo
    {
        return $this->belongsTo(PlanRequirement::class);
    }
}
