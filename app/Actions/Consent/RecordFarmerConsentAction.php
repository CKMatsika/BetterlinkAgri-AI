<?php

declare(strict_types=1);

namespace App\Actions\Consent;

use App\Enums\LanguageCode;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\ConsentEvent;
use App\Models\Conversation;
use App\Models\Farmer;

final class RecordFarmerConsentAction
{
    public function execute(
        Conversation $conversation,
        Farmer $farmer,
        bool $marketingConsent = true
    ): Consent {
        $policyVersion = (string) config('betterlink.cdpa.current_policy_version', '2026.1');

        $consent = Consent::query()->updateOrCreate(
            [
                'tenant_id' => $conversation->tenant_id,
                'farmer_id' => $farmer->id,
            ],
            [
                'channel' => $conversation->channel,
                'status' => 'opted_in',
                'data_processing_consent' => true,
                'marketing_consent' => $marketingConsent,
                'policy_version' => $policyVersion,
                'opt_out_keyword_used' => null,
                'consented_at' => now(),
                'revoked_at' => null,
            ]
        );

        ConsentEvent::record(
            tenantId: $conversation->tenant_id,
            farmerId: $farmer->id,
            scope: 'cdpa_processing',
            action: 'granted',
            channel: $conversation->channel,
            source: 'farmer_reply',
            termsVersion: $policyVersion
        );

        ConsentEvent::record(
            tenantId: $conversation->tenant_id,
            farmerId: $farmer->id,
            scope: 'marketing',
            action: $marketingConsent ? 'granted' : 'revoked',
            channel: $conversation->channel,
            source: 'farmer_reply',
            termsVersion: $policyVersion
        );

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'event_type' => 'consent_granted',
            'metadata_json' => [
                'farmer_id' => $farmer->id,
                'policy_version' => $consent->policy_version,
            ],
            'created_at' => now(),
        ]);

        return $consent;
    }

    public function buildConsentPromptText(LanguageCode $language, string $tenantName): string
    {
        return match ($language) {
            LanguageCode::SHONA => "Mauya ku {$tenantName} AI Mudhumeni. Kuti tikubatsirei kuongorora mbesa nezvipfuyo zvenyu pamwe nekutsvaga mishonga pedyo nemi (Cyber & Data Protection Act), tinochengeta nhamba yenyu, mifananidzo yeminda nedunhu renyu.\n\n1. Hongu, ndinobvuma (Yes)\n2. Kwete (No)\n\n(Tumirai MIRA chero nguva kumisa).",
            LanguageCode::NDEBELE => "Siyalemukela ku {$tenantName} AI Umlimisi. Ukuze silisize ukuhlola izilimo lezifuyo zenu lokuthola imithi eduzane lani (Cyber & Data Protection Act), sigcina inombolo yenu, imifanekiso kanye lesigaba senu.\n\n1. Yebo, ngiyavuma (Yes)\n2. Hatshi (No)\n\n(Thumelani MANA loba nini ukumisa).",
            LanguageCode::ENGLISH => "Welcome to {$tenantName} AI Agronomy Advisor. Under Zimbabwe's Cyber & Data Protection Act, we store your phone number, crop/livestock photos, and ward location to diagnose farm problems and find stocked outlets near you.\n\nReply:\n1. Yes, I agree\n2. No\n\n(Reply STOP, MIRA or MANA anytime to opt out).",
        };
    }
}
