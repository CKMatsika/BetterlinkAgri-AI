<?php

declare(strict_types=1);

namespace App\Actions\Consent;

use App\Enums\ConversationState;
use App\Enums\LanguageCode;
use App\Models\AuditLog;
use App\Models\Consent;
use App\Models\ConsentEvent;
use App\Models\Conversation;
use App\Models\Farmer;

final class ProcessOptOutOrOptInAction
{
    public function isOptOutKeyword(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $normalized = strtoupper(trim($text));
        /** @var list<string> $keywords */
        $keywords = config('betterlink.localization.opt_out_keywords', ['STOP', 'MIRA', 'MANA']);

        return in_array($normalized, array_map('strtoupper', $keywords), true);
    }

    public function isOptInKeyword(?string $text): bool
    {
        if ($text === null) {
            return false;
        }

        $normalized = strtoupper(trim($text));
        /** @var list<string> $keywords */
        $keywords = config('betterlink.localization.opt_in_keywords', ['START', 'TANGA', 'QALA']);

        return in_array($normalized, array_map('strtoupper', $keywords), true);
    }

    public function executeOptOut(Conversation $conversation, Farmer $farmer, string $keywordUsed): string
    {
        $previousState = $conversation->state;

        Consent::query()->updateOrCreate(
            [
                'tenant_id' => $conversation->tenant_id,
                'farmer_id' => $farmer->id,
            ],
            [
                'channel' => $conversation->channel,
                'status' => 'opted_out',
                'data_processing_consent' => false,
                'marketing_consent' => false,
                'opt_out_keyword_used' => strtoupper(trim($keywordUsed)),
                'revoked_at' => now(),
            ]
        );

        foreach (['cdpa_processing', 'marketing'] as $scope) {
            ConsentEvent::record(
                tenantId: $conversation->tenant_id,
                farmerId: $farmer->id,
                scope: $scope,
                action: 'revoked',
                channel: $conversation->channel,
                source: 'opt_out_keyword',
                metadata: ['keyword' => strtoupper(trim($keywordUsed))]
            );
        }

        $conversation->update([
            'state' => ConversationState::OPTED_OUT,
            'state_updated_at' => now(),
        ]);

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'event_type' => 'opt_out',
            'from_state' => $previousState->value,
            'to_state' => ConversationState::OPTED_OUT->value,
            'metadata_json' => [
                'keyword' => strtoupper(trim($keywordUsed)),
                'farmer_id' => $farmer->id,
            ],
            'created_at' => now(),
        ]);

        return match ($conversation->language) {
            LanguageCode::SHONA => 'Mamisikidzwa kubuda muhurongwa huno (Opted out). Hamuchatambiri mameseji. Tumirai START kana TANGA kana muchida kutanga patsva.',
            LanguageCode::NDEBELE => 'Seliphumile kuhlelo lolu (Opted out). Alisayikuthola imibiko. Thumelani START kumbe QALA nxa lifuna ukuqala futhi.',
            LanguageCode::ENGLISH => 'You have been opted out and will receive no further messages. Reply START at any time to re-enable advisory services.',
        };
    }

    public function executeOptInFromKeyword(Conversation $conversation, Farmer $farmer): void
    {
        $previousState = $conversation->state;

        $conversation->update([
            'state' => ConversationState::CONSENT_CHECK,
            'state_updated_at' => now(),
        ]);

        AuditLog::query()->create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'event_type' => 'state_transition',
            'from_state' => $previousState->value,
            'to_state' => ConversationState::CONSENT_CHECK->value,
            'metadata_json' => [
                'trigger' => 'START_KEYWORD',
                'farmer_id' => $farmer->id,
            ],
            'created_at' => now(),
        ]);
    }
}
