<?php

declare(strict_types=1);

namespace App\Actions\Consent;

use App\Models\AuditLog;
use App\Models\Farmer;
use App\Models\LocationConsent;

final class RecordLocationConsentAction
{
    public function execute(
        string $tenantId,
        Farmer $farmer,
        bool $gpsCaptureConsent = true,
        bool $shareExactWithOutletConsent = false,
        ?string $conversationId = null
    ): LocationConsent {
        $locationConsent = LocationConsent::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'farmer_id' => $farmer->id,
            ],
            [
                'gps_capture_consent' => $gpsCaptureConsent,
                'share_exact_with_outlet_consent' => $shareExactWithOutletConsent,
                'consented_at' => now(),
                'revoked_at' => null,
            ]
        );

        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'event_type' => 'location_consent_updated',
            'metadata_json' => [
                'farmer_id' => $farmer->id,
                'gps_capture_consent' => $gpsCaptureConsent,
                'share_exact_with_outlet_consent' => $shareExactWithOutletConsent,
            ],
            'created_at' => now(),
        ]);

        return $locationConsent;
    }
}
