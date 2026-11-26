<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CurrencyCode;
use App\Enums\MarketplaceRequestStatus;
use App\Models\Concerns\ScopedByMarketplace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class MarketplaceRequest extends Model
{
    use HasUuids;
    use ScopedByMarketplace;
    use SoftDeletes;

    protected $table = 'requests';

    protected $fillable = [
        'farmer_id',
        'conversation_id',
        'diagnosis_id',
        'origin_organisation_id',
        'request_type',
        'status',
        'source',
        'province_id',
        'district_id',
        'ward_id',
        'approx_latitude',
        'approx_longitude',
        'exact_location_encrypted',
        'farmer_phone_encrypted',
        'delivery_preference',
        'needed_by_date',
        'max_budget_minor',
        'currency',
        'current_ring',
        'ring_expires_at',
        'expires_at',
        'metadata_json',
    ];

    protected $hidden = [
        'exact_location_encrypted',
        'farmer_phone_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'status' => MarketplaceRequestStatus::class,
            'currency' => CurrencyCode::class,
            'approx_latitude' => 'decimal:7',
            'approx_longitude' => 'decimal:7',
            'exact_location_encrypted' => 'encrypted',
            'farmer_phone_encrypted' => 'encrypted',
            'needed_by_date' => 'date',
            'max_budget_minor' => 'integer',
            'current_ring' => 'integer',
            'ring_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    /**
     * Reveal exact farmer location and phone to a retailer ONLY after:
     * 1) an accepted Order exists for that retailer on this request, AND
     * 2) the farmer has an active granted 'marketplace_contact_reveal' ConsentEvent.
     *
     * @return array{exact_location: ?string, farmer_phone: ?string}|null
     */
    public function revealFarmerContactFor(string $organisationId): ?array
    {
        $hasAcceptedOrder = MarketplaceOrder::withoutGlobalScopes()
            ->where('request_id', '=', $this->id)
            ->where('organisation_id', '=', $organisationId)
            ->whereNotIn('status', ['cancelled'])
            ->exists();

        if (!$hasAcceptedOrder) {
            return null;
        }

        $hasConsent = ConsentEvent::hasActiveConsent($organisationId, $this->farmer_id, 'marketplace_contact_reveal')
            || ConsentEvent::hasActiveConsent(null, $this->farmer_id, 'marketplace_contact_reveal');

        if (!$hasConsent) {
            return null;
        }

        return [
            'exact_location' => $this->exact_location_encrypted,
            'farmer_phone' => $this->farmer_phone_encrypted,
        ];
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequestItem::class, 'request_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(RequestRecipient::class, 'request_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class, 'request_id');
    }

    public function order(): HasOne
    {
        return $this->hasOne(MarketplaceOrder::class, 'request_id');
    }
}
