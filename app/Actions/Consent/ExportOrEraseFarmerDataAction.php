<?php

declare(strict_types=1);

namespace App\Actions\Consent;

use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\Conversation;
use App\Models\Diagnosis;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\FarmerLocation;
use App\Models\Lead;
use App\Models\LocationConsent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Zimbabwe Cyber and Data Protection Act [Chapter 12:07] Data Subject Rights:
 * 1. Portable JSON export of farmer personal and farm records
 * 2. Right to erasure / cryptographic PII anonymization
 */
final class ExportOrEraseFarmerDataAction
{
    /**
     * @return array<string, mixed>
     */
    public function exportForTenant(string $tenantId, Farmer $farmer): array
    {
        $consent = Consent::query()
            ->where('tenant_id', '=', $tenantId)
            ->where('farmer_id', '=', $farmer->id)
            ->first();

        $locationConsent = LocationConsent::query()
            ->where('tenant_id', '=', $tenantId)
            ->where('farmer_id', '=', $farmer->id)
            ->first();

        $farms = Farm::query()
            ->where('farmer_id', '=', $farmer->id)
            ->with('ward.district.province')
            ->get();

        $conversations = Conversation::query()
            ->where('tenant_id', '=', $tenantId)
            ->where('farmer_id', '=', $farmer->id)
            ->with(['messages', 'diagnoses.advisory'])
            ->get();

        $leads = Lead::query()
            ->where('tenant_id', '=', $tenantId)
            ->where('farmer_id', '=', $farmer->id)
            ->get();

        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'event_type' => 'cdpa_data_export',
            'metadata_json' => ['farmer_id' => $farmer->id],
            'created_at' => now(),
        ]);

        return [
            'exported_at' => now()->toIso8601String(),
            'regulatory_framework' => 'Zimbabwe Cyber and Data Protection Act [Chapter 12:07]',
            'farmer_profile' => [
                'id' => $farmer->id,
                'phone' => $farmer->phone_encrypted,
                'full_name' => $farmer->full_name_encrypted,
                'preferred_language' => $farmer->preferred_language->value,
                'farming_sector' => $farmer->farming_sector?->value,
                'is_pfumvudza_enrolled' => $farmer->is_pfumvudza_enrolled,
                'is_contract_farmer' => $farmer->is_contract_farmer,
            ],
            'consents' => [
                'cdpa_consent' => $consent?->toArray(),
                'location_consent' => $locationConsent?->toArray(),
            ],
            'farms' => $farms->toArray(),
            'conversations' => $conversations->toArray(),
            'leads' => $leads->toArray(),
        ];
    }

    public function eraseAndAnonymize(string $tenantId, Farmer $farmer): void
    {
        DB::transaction(function () use ($tenantId, $farmer): void {
            FarmerLocation::query()
                ->where('farmer_id', '=', $farmer->id)
                ->where('tenant_id', '=', $tenantId)
                ->delete();

            LocationConsent::query()
                ->where('farmer_id', '=', $farmer->id)
                ->where('tenant_id', '=', $tenantId)
                ->delete();

            Consent::query()
                ->where('farmer_id', '=', $farmer->id)
                ->where('tenant_id', '=', $tenantId)
                ->update([
                    'status' => 'opted_out',
                    'data_processing_consent' => false,
                    'marketing_consent' => false,
                    'revoked_at' => now(),
                ]);

            Diagnosis::query()
                ->where('farmer_id', '=', $farmer->id)
                ->where('tenant_id', '=', $tenantId)
                ->delete();

            Conversation::query()
                ->where('farmer_id', '=', $farmer->id)
                ->where('tenant_id', '=', $tenantId)
                ->delete();

            // Anonymize PII on the farmer record if no other active tenant consents remain
            $remainingActiveConsents = Consent::withoutGlobalScopes()
                ->where('farmer_id', '=', $farmer->id)
                ->where('status', '=', 'opted_in')
                ->count();

            if ($remainingActiveConsents === 0) {
                $redactedToken = 'REDACTED_' . Str::random(24);
                $farmer->update([
                    'phone_hash' => hash('sha256', $redactedToken),
                    'phone_encrypted' => 'REDACTED',
                    'full_name_encrypted' => 'REDACTED',
                    'national_id_encrypted' => null,
                ]);
                $farmer->delete();
            }

            AuditLog::query()->create([
                'tenant_id' => $tenantId,
                'event_type' => 'cdpa_right_to_erasure',
                'metadata_json' => ['farmer_id' => $farmer->id],
                'created_at' => now(),
            ]);
        });
    }
}
