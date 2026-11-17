<?php

declare(strict_types=1);

namespace App\Services\Channels;

use App\Contracts\MessagingChannelContract;
use App\DTOs\InboundMessageData;
use App\Models\Conversation;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class WhatsAppCloudService implements MessagingChannelContract
{
    /**
     * Parse a raw WhatsApp Cloud API webhook payload into structured InboundMessageData DTOs.
     *
     * @param array<string, mixed> $payload
     * @return list<InboundMessageData>
     */
    public function parseWebhookPayload(array $payload): array
    {
        $parsedMessages = [];
        $entries = (array) ($payload['entry'] ?? []);

        foreach ($entries as $entry) {
            $changes = (array) ($entry['changes'] ?? []);
            foreach ($changes as $change) {
                $value = (array) ($change['value'] ?? []);
                $metadata = (array) ($value['metadata'] ?? []);
                $phoneNumberId = isset($metadata['phone_number_id']) ? (string) $metadata['phone_number_id'] : null;

                $tenant = $this->resolveTenantByPhoneNumberId($phoneNumberId);
                if ($tenant === null) {
                    continue;
                }

                $contacts = (array) ($value['contacts'] ?? []);
                $contactNamesByWaId = [];
                foreach ($contacts as $contact) {
                    $waId = (string) ($contact['wa_id'] ?? '');
                    $profileName = isset($contact['profile']['name']) ? (string) $contact['profile']['name'] : null;
                    if ($waId !== '') {
                        $contactNamesByWaId[$waId] = $profileName;
                    }
                }

                $messages = (array) ($value['messages'] ?? []);
                foreach ($messages as $msg) {
                    $externalId = (string) ($msg['id'] ?? '');
                    $fromPhone = (string) ($msg['from'] ?? '');
                    $type = (string) ($msg['type'] ?? 'text');

                    if ($externalId === '' || $fromPhone === '') {
                        continue;
                    }

                    $senderName = $contactNamesByWaId[$fromPhone] ?? null;
                    $bodyText = null;
                    $mediaId = null;
                    $mediaMimeType = null;
                    $lat = null;
                    $lng = null;
                    $interactiveId = null;
                    $interactiveTitle = null;

                    if ($type === 'text') {
                        $bodyText = isset($msg['text']['body']) ? (string) $msg['text']['body'] : null;
                    } elseif ($type === 'image') {
                        $mediaId = isset($msg['image']['id']) ? (string) $msg['image']['id'] : null;
                        $mediaMimeType = isset($msg['image']['mime_type']) ? (string) $msg['image']['mime_type'] : 'image/jpeg';
                        $bodyText = isset($msg['image']['caption']) ? (string) $msg['image']['caption'] : null;
                    } elseif ($type === 'audio' || $type === 'voice') {
                        $audioNode = (array) ($msg[$type] ?? []);
                        $mediaId = isset($audioNode['id']) ? (string) $audioNode['id'] : null;
                        $mediaMimeType = isset($audioNode['mime_type']) ? (string) $audioNode['mime_type'] : 'audio/ogg';
                        $type = 'audio';
                    } elseif ($type === 'location') {
                        $lat = isset($msg['location']['latitude']) ? (float) $msg['location']['latitude'] : null;
                        $lng = isset($msg['location']['longitude']) ? (float) $msg['location']['longitude'] : null;
                        $bodyText = isset($msg['location']['name']) ? (string) $msg['location']['name'] : null;
                    } elseif ($type === 'interactive') {
                        $interactive = (array) ($msg['interactive'] ?? []);
                        $intType = (string) ($interactive['type'] ?? '');
                        if ($intType === 'button_reply') {
                            $interactiveId = isset($interactive['button_reply']['id']) ? (string) $interactive['button_reply']['id'] : null;
                            $interactiveTitle = isset($interactive['button_reply']['title']) ? (string) $interactive['button_reply']['title'] : null;
                            $bodyText = $interactiveTitle ?? $interactiveId;
                        } elseif ($intType === 'list_reply') {
                            $interactiveId = isset($interactive['list_reply']['id']) ? (string) $interactive['list_reply']['id'] : null;
                            $interactiveTitle = isset($interactive['list_reply']['title']) ? (string) $interactive['list_reply']['title'] : null;
                            $bodyText = $interactiveTitle ?? $interactiveId;
                        }
                    }

                    $parsedMessages[] = new InboundMessageData(
                        tenantId: (string) $tenant->id,
                        channel: 'whatsapp',
                        externalMessageId: $externalId,
                        senderPhone: $fromPhone,
                        senderName: $senderName,
                        messageType: $type,
                        bodyText: $bodyText,
                        mediaId: $mediaId,
                        mediaMimeType: $mediaMimeType,
                        latitude: $lat,
                        longitude: $lng,
                        interactiveReplyId: $interactiveId,
                        interactiveReplyTitle: $interactiveTitle,
                        rawPayload: (array) $msg,
                    );
                }
            }
        }

        return $parsedMessages;
    }

    /**
     * Download binary media content from Meta WhatsApp Cloud API using media_id.
     */
    public function downloadMedia(Tenant $tenant, string $mediaId): string
    {
        $accessToken = $this->resolveAccessToken($tenant);
        $graphUri = (string) config('betterlink.whatsapp.graph_uri', 'https://graph.facebook.com');
        $version = (string) config('betterlink.whatsapp.graph_version', 'v21.0');

        $metaResponse = Http::withToken($accessToken)
            ->timeout(20)
            ->get("{$graphUri}/{$version}/{$mediaId}");

        if (!$metaResponse->successful()) {
            throw new RuntimeException("Failed to fetch WhatsApp media URL for ID {$mediaId}.");
        }

        $downloadUrl = (string) $metaResponse->json('url', '');
        if ($downloadUrl === '') {
            throw new RuntimeException("Empty download URL returned for WhatsApp media ID {$mediaId}.");
        }

        $binaryResponse = Http::withToken($accessToken)
            ->timeout(30)
            ->get($downloadUrl);

        if (!$binaryResponse->successful()) {
            throw new RuntimeException("Failed to download binary media from WhatsApp URL for ID {$mediaId}.");
        }

        return $binaryResponse->body();
    }

    public function sendText(Conversation $conversation, string $text): ?string
    {
        $recipient = $this->resolveRecipientPhone($conversation);

        return $this->dispatchGraphMessage($conversation->tenant, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => Str::limit($text, 4000, '...'),
            ],
        ]);
    }

    /**
     * @param list<array{id: string, title: string}> $buttons
     */
    public function sendInteractiveButtons(
        Conversation $conversation,
        string $bodyText,
        array $buttons,
        ?string $headerText = null,
        ?string $footerText = null
    ): ?string {
        $recipient = $this->resolveRecipientPhone($conversation);

        // Enforce WhatsApp Cloud API limit: max 3 buttons, max 20 chars per button title
        $formattedButtons = [];
        foreach (array_slice($buttons, 0, 3) as $btn) {
            $formattedButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => Str::limit($btn['id'], 256, ''),
                    'title' => Str::limit($btn['title'], 20, ''),
                ],
            ];
        }

        $interactive = [
            'type' => 'button',
            'body' => ['text' => Str::limit($bodyText, 1024, '...')],
            'action' => ['buttons' => $formattedButtons],
        ];

        if ($headerText !== null && $headerText !== '') {
            $interactive['header'] = [
                'type' => 'text',
                'text' => Str::limit($headerText, 60, ''),
            ];
        }

        if ($footerText !== null && $footerText !== '') {
            $interactive['footer'] = [
                'text' => Str::limit($footerText, 60, ''),
            ];
        }

        return $this->dispatchGraphMessage($conversation->tenant, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * @param list<array{id: string, title: string, description?: string}> $rows
     */
    public function sendInteractiveList(
        Conversation $conversation,
        string $bodyText,
        string $buttonLabel,
        array $rows,
        ?string $headerText = null
    ): ?string {
        $recipient = $this->resolveRecipientPhone($conversation);

        // Enforce WhatsApp Cloud API limit: max 10 rows, max 24 chars per row title
        $formattedRows = [];
        foreach (array_slice($rows, 0, 10) as $row) {
            $item = [
                'id' => Str::limit($row['id'], 200, ''),
                'title' => Str::limit($row['title'], 24, ''),
            ];
            if (!empty($row['description'])) {
                $item['description'] = Str::limit($row['description'], 72, '');
            }
            $formattedRows[] = $item;
        }

        $interactive = [
            'type' => 'list',
            'body' => ['text' => Str::limit($bodyText, 1024, '...')],
            'action' => [
                'button' => Str::limit($buttonLabel, 20, ''),
                'sections' => [
                    [
                        'title' => 'Options',
                        'rows' => $formattedRows,
                    ],
                ],
            ],
        ];

        if ($headerText !== null && $headerText !== '') {
            $interactive['header'] = [
                'type' => 'text',
                'text' => Str::limit($headerText, 60, ''),
            ];
        }

        return $this->dispatchGraphMessage($conversation->tenant, [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $recipient,
            'type' => 'interactive',
            'interactive' => $interactive,
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function dispatchGraphMessage(?Tenant $tenant, array $body): ?string
    {
        if (!config('betterlink.whatsapp.enabled', true)) {
            return 'wa_mock_' . Str::uuid()->toString();
        }

        $phoneNumberId = $tenant?->whatsapp_phone_number_id
            ?: (string) config('betterlink.whatsapp.default_phone_number_id', '');
        $accessToken = $this->resolveAccessToken($tenant);

        if ($phoneNumberId === '' || $accessToken === '') {
            return 'wa_local_' . Str::uuid()->toString();
        }

        $graphUri = (string) config('betterlink.whatsapp.graph_uri', 'https://graph.facebook.com');
        $version = (string) config('betterlink.whatsapp.graph_version', 'v21.0');

        $response = Http::withToken($accessToken)
            ->timeout(20)
            ->post("{$graphUri}/{$version}/{$phoneNumberId}/messages", $body);

        if (!$response->successful()) {
            throw new RuntimeException('WhatsApp Cloud API send failed: ' . $response->body());
        }

        return (string) ($response->json('messages.0.id') ?? '');
    }

    private function resolveTenantByPhoneNumberId(?string $phoneNumberId): ?Tenant
    {
        if ($phoneNumberId !== null && $phoneNumberId !== '') {
            $tenant = Tenant::query()
                ->where('whatsapp_phone_number_id', '=', $phoneNumberId)
                ->where('is_active', '=', true)
                ->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        return Tenant::query()->where('is_active', '=', true)->first();
    }

    private function resolveAccessToken(?Tenant $tenant): string
    {
        if ($tenant !== null && !empty($tenant->whatsapp_access_token_encrypted)) {
            return (string) $tenant->whatsapp_access_token_encrypted;
        }

        return (string) config('betterlink.whatsapp.access_token', '');
    }

    private function resolveRecipientPhone(Conversation $conversation): string
    {
        $raw = (string) ($conversation->farmer?->phone_encrypted ?? $conversation->external_thread_id ?? '');

        return ltrim($raw, '+');
    }
}
