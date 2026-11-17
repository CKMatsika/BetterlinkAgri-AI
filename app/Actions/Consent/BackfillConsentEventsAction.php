<?php

declare(strict_types=1);

namespace App\Actions\Consent;

use App\Models\Consent;
use App\Models\ConsentEvent;
use App\Models\LocationConsent;
use Illuminate\Support\Str;

/**
 * Idempotently backfills legacy `consents` and `location_consents` rows into the
 * append-only `consent_events` ledger.
 */
final class BackfillConsentEventsAction
{
    /**
     * @return array{consents_processed: int, location_consents_processed: int, events_created: int}
     */
    public function execute(): array
    {
        $consentsProcessed = 0;
        $locationConsentsProcessed = 0;
        $eventsCreated = 0;

        Consent::query()->orderBy('created_at')->chunk(100, function ($rows) use (&$consentsProcessed, &$eventsCreated): void {
            foreach ($rows as $consent) {
                $consentsProcessed++;
                $channel = $consent->channel ?: 'whatsapp';
                $terms = $consent->policy_version;
                $grantedAt = $consent->consented_at ?? $consent->created_at ?? now();

                if ($consent->data_processing_consent) {
                    $eventsCreated += $this->recordOnce(
                        tenantId: $consent->tenant_id,
                        farmerId: $consent->farmer_id,
                        scope: 'data_processing',
                        action: 'granted',
                        occurredAt: $grantedAt,
                        termsVersion: $terms,
                        channel: $channel,
                        legacyTable: 'consents',
                        legacyId: (string) $consent->id
                    );
                }

                if ($consent->marketing_consent) {
                    $eventsCreated += $this->recordOnce(
                        tenantId: $consent->tenant_id,
                        farmerId: $consent->farmer_id,
                        scope: 'marketing',
                        action: 'granted',
                        occurredAt: $grantedAt,
                        termsVersion: $terms,
                        channel: $channel,
                        legacyTable: 'consents',
                        legacyId: (string) $consent->id
                    );
                }

                if ($consent->isOptedOut()) {
                    $revokedAt = $consent->revoked_at ?? $consent->updated_at ?? now();
                    foreach (['data_processing', 'marketing'] as $scope) {
                        $eventsCreated += $this->recordOnce(
                            tenantId: $consent->tenant_id,
                            farmerId: $consent->farmer_id,
                            scope: $scope,
                            action: 'revoked',
                            occurredAt: $revokedAt,
                            termsVersion: $terms,
                            channel: $channel,
                            legacyTable: 'consents',
                            legacyId: (string) $consent->id
                        );
                    }
                }
            }
        });

        LocationConsent::query()->orderBy('created_at')->chunk(100, function ($rows) use (&$locationConsentsProcessed, &$eventsCreated): void {
            foreach ($rows as $loc) {
                $locationConsentsProcessed++;
                $grantedAt = $loc->consented_at ?? $loc->created_at ?? now();

                if ($loc->gps_capture_consent) {
                    $eventsCreated += $this->recordOnce(
                        tenantId: $loc->tenant_id,
                        farmerId: $loc->farmer_id,
                        scope: 'gps_capture',
                        action: 'granted',
                        occurredAt: $grantedAt,
                        termsVersion: null,
                        channel: 'whatsapp',
                        legacyTable: 'location_consents',
                        legacyId: (string) $loc->id
                    );
                }

                if ($loc->share_exact_with_outlet_consent) {
                    $eventsCreated += $this->recordOnce(
                        tenantId: $loc->tenant_id,
                        farmerId: $loc->farmer_id,
                        scope: 'share_exact_location',
                        action: 'granted',
                        occurredAt: $grantedAt,
                        termsVersion: null,
                        channel: 'whatsapp',
                        legacyTable: 'location_consents',
                        legacyId: (string) $loc->id
                    );
                }

                if ($loc->revoked_at !== null) {
                    foreach (['gps_capture', 'share_exact_location'] as $scope) {
                        $eventsCreated += $this->recordOnce(
                            tenantId: $loc->tenant_id,
                            farmerId: $loc->farmer_id,
                            scope: $scope,
                            action: 'revoked',
                            occurredAt: $loc->revoked_at,
                            termsVersion: null,
                            channel: 'whatsapp',
                            legacyTable: 'location_consents',
                            legacyId: (string) $loc->id
                        );
                    }
                }
            }
        });

        return [
            'consents_processed' => $consentsProcessed,
            'location_consents_processed' => $locationConsentsProcessed,
            'events_created' => $eventsCreated,
        ];
    }

    private function recordOnce(
        ?string $tenantId,
        string $farmerId,
        string $scope,
        string $action,
        mixed $occurredAt,
        ?string $termsVersion,
        string $channel,
        string $legacyTable,
        string $legacyId
    ): int {
        $exists = ConsentEvent::query()
            ->when(
                $tenantId !== null,
                fn ($q) => $q->where('tenant_id', '=', $tenantId),
                fn ($q) => $q->whereNull('tenant_id')
            )
            ->where('farmer_id', '=', $farmerId)
            ->where('scope', '=', $scope)
            ->where('action', '=', $action)
            ->where('source', '=', 'backfill_migration')
            ->exists();

        if ($exists) {
            return 0;
        }

        ConsentEvent::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'farmer_id' => $farmerId,
            'scope' => $scope,
            'action' => $action,
            'occurred_at' => $occurredAt,
            'terms_version' => $termsVersion ?? 'v1.0-cdpa',
            'channel' => $channel,
            'source' => 'backfill_migration',
            'metadata_json' => [
                'backfilled_from_table' => $legacyTable,
                'backfilled_from_id' => $legacyId,
            ],
            'created_at' => now(),
        ]);

        return 1;
    }
}
