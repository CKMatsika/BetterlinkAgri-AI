<?php

declare(strict_types=1);

namespace App\Services\Engagement;

use App\Enums\LanguageCode;
use App\Jobs\SendOutboundChannelMessageJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Consent;
use App\Models\Conversation;
use App\Models\Farmer;

/**
 * Section 9: Segmented Campaign Broadcast Service.
 *
 * Segments by crop, Natural Region, district, ward, and farming sector.
 * Enforces:
 * - Only sends if WhatsApp template status is 'approved'
 * - Strictly excludes any farmer who has opted out (STOP / MIRA / MANA) or lacks active CDPA consent
 * - Localises message body in the farmer's preferred language (en, sn, nd)
 */
final class CampaignBroadcastService
{
    public function launchCampaign(Campaign $campaign): int
    {
        if ($campaign->whatsapp_template_status !== 'approved') {
            return 0;
        }

        $filters = (array) ($campaign->segment_filter_json ?? []);
        $wardIds = array_map('strval', (array) ($filters['ward_ids'] ?? []));
        $districtIds = array_map('strval', (array) ($filters['district_ids'] ?? []));
        $naturalRegions = array_map('strval', (array) ($filters['natural_regions'] ?? []));
        $sectors = array_map('strval', (array) ($filters['farming_sectors'] ?? []));

        // Fetch only farmers with active opted-in Consent for this tenant
        $optedInFarmerIds = Consent::withoutGlobalScopes()
            ->where('tenant_id', '=', $campaign->tenant_id)
            ->where('status', '=', 'opted_in')
            ->where('data_processing_consent', '=', true)
            ->whereNull('revoked_at')
            ->pluck('farmer_id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if ($optedInFarmerIds === []) {
            return 0;
        }

        $farmerQuery = Farmer::query()->whereIn('id', $optedInFarmerIds);

        if ($wardIds !== []) {
            $farmerQuery->whereIn('ward_id', $wardIds);
        }

        if ($districtIds !== []) {
            $farmerQuery->whereHas('ward', fn ($q) => $q->whereIn('district_id', $districtIds));
        }

        if ($naturalRegions !== []) {
            $farmerQuery->whereHas('ward', fn ($q) => $q->whereIn('natural_region', $naturalRegions));
        }

        if ($sectors !== []) {
            $farmerQuery->whereIn('farming_sector', $sectors);
        }

        $farmers = $farmerQuery->get();
        $queuedCount = 0;

        $campaign->update(['status' => 'running']);

        foreach ($farmers as $farmer) {
            $recipient = CampaignRecipient::withoutGlobalScopes()->updateOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'farmer_id' => $farmer->id,
                ],
                [
                    'tenant_id' => $campaign->tenant_id,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]
            );

            $conversation = Conversation::withoutGlobalScopes()->firstOrCreate(
                [
                    'tenant_id' => $campaign->tenant_id,
                    'farmer_id' => $farmer->id,
                    'channel' => 'whatsapp',
                ],
                [
                    'external_thread_id' => $farmer->phone_encrypted,
                    'language' => $farmer->preferred_language,
                    'last_message_at' => now(),
                ]
            );

            $localizedBody = match ($farmer->preferred_language) {
                LanguageCode::SHONA => $campaign->body_sn ?: $campaign->body_en,
                LanguageCode::NDEBELE => $campaign->body_nd ?: $campaign->body_en,
                LanguageCode::ENGLISH => $campaign->body_en,
            };

            SendOutboundChannelMessageJob::dispatch($conversation->id, $localizedBody);
            $queuedCount++;
        }

        $campaign->update(['status' => 'completed']);

        return $queuedCount;
    }
}
