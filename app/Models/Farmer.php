<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FarmingSector;
use App\Enums\LanguageCode;
use App\Models\Concerns\HasEncryptedPii;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Farmer extends Model
{
    use HasEncryptedPii;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'ward_id',
        'phone_hash',
        'phone_encrypted',
        'full_name_encrypted',
        'national_id_encrypted',
        'preferred_language',
        'farming_sector',
        'is_pfumvudza_enrolled',
        'is_contract_farmer',
        'suppliers_used_json',
        'preferred_contact_time',
        'last_active_at',
    ];

    protected function casts(): array
    {
        return [
            'phone_encrypted' => 'encrypted',
            'full_name_encrypted' => 'encrypted',
            'national_id_encrypted' => 'encrypted',
            'preferred_language' => LanguageCode::class,
            'farming_sector' => FarmingSector::class,
            'is_pfumvudza_enrolled' => 'boolean',
            'is_contract_farmer' => 'boolean',
            'suppliers_used_json' => 'array',
            'last_active_at' => 'datetime',
        ];
    }

    public static function findByPhone(string $rawPhone): ?self
    {
        $hash = self::hashPhone($rawPhone);

        return self::query()->where('phone_hash', '=', $hash)->first();
    }

    public static function findOrCreateByPhone(string $rawPhone, ?string $name = null): self
    {
        $normalized = self::normalizeZimbabwePhone($rawPhone);
        $hash = self::hashPhone($normalized);

        $farmer = self::query()->where('phone_hash', '=', $hash)->first();

        if ($farmer !== null) {
            if ($name !== null && empty($farmer->full_name_encrypted)) {
                $farmer->update(['full_name_encrypted' => $name]);
            }

            return $farmer;
        }

        return self::query()->create([
            'phone_hash' => $hash,
            'phone_encrypted' => $normalized,
            'full_name_encrypted' => $name,
            'preferred_language' => LanguageCode::ENGLISH,
            'last_active_at' => now(),
        ]);
    }

    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    public function farms(): HasMany
    {
        return $this->hasMany(Farm::class);
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function locationConsents(): HasMany
    {
        return $this->hasMany(LocationConsent::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(FarmerLocation::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
