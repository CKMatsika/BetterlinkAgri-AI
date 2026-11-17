<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only consent ledger. Current consent for any (tenant_id, farmer_id, scope)
 * is derived from the latest ConsentEvent ordered by occurred_at DESC, created_at DESC.
 */
class ConsentEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'farmer_id',
        'scope',
        'action',
        'occurred_at',
        'terms_version',
        'channel',
        'source',
        'metadata_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Derive whether a farmer currently holds an active granted consent for a given scope.
     */
    public static function hasActiveConsent(?string $tenantId, string $farmerId, string $scope): bool
    {
        $latest = self::query()
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where('tenant_id', '=', $tenantId),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->where('farmer_id', '=', $farmerId)
            ->where('scope', '=', $scope)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->first();

        return $latest !== null && $latest->action === 'granted';
    }

    /**
     * Append a new immutable consent event.
     *
     * @param array<string, mixed> $metadata
     */
    public static function record(
        ?string $tenantId,
        string $farmerId,
        string $scope,
        string $action,
        string $channel = 'whatsapp',
        string $source = 'farmer_reply',
        ?string $termsVersion = null,
        array $metadata = []
    ): self {
        return self::query()->create([
            'tenant_id' => $tenantId,
            'farmer_id' => $farmerId,
            'scope' => $scope,
            'action' => $action,
            'occurred_at' => now(),
            'terms_version' => $termsVersion ?? (string) config('betterlink.cdpa.current_policy_version', '2026.1'),
            'channel' => $channel,
            'source' => $source,
            'metadata_json' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(Farmer::class);
    }
}
