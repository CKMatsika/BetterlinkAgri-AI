<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class InboundMessageData
{
    /**
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public string $tenantId,
        public string $channel,
        public string $externalMessageId,
        public string $senderPhone,
        public ?string $senderName,
        public string $messageType, // text|image|audio|location|interactive
        public ?string $bodyText = null,
        public ?string $mediaId = null,
        public ?string $mediaMimeType = null,
        public ?string $mediaUrl = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $interactiveReplyId = null,
        public ?string $interactiveReplyTitle = null,
        public array $rawPayload = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'channel' => $this->channel,
            'external_message_id' => $this->externalMessageId,
            'sender_phone' => $this->senderPhone,
            'sender_name' => $this->senderName,
            'message_type' => $this->messageType,
            'body_text' => $this->bodyText,
            'media_id' => $this->mediaId,
            'media_mime_type' => $this->mediaMimeType,
            'media_url' => $this->mediaUrl,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'interactive_reply_id' => $this->interactiveReplyId,
            'interactive_reply_title' => $this->interactiveReplyTitle,
            'raw_payload' => $this->rawPayload,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tenantId: (string) $data['tenant_id'],
            channel: (string) ($data['channel'] ?? 'whatsapp'),
            externalMessageId: (string) $data['external_message_id'],
            senderPhone: (string) $data['sender_phone'],
            senderName: isset($data['sender_name']) ? (string) $data['sender_name'] : null,
            messageType: (string) ($data['message_type'] ?? 'text'),
            bodyText: isset($data['body_text']) ? (string) $data['body_text'] : null,
            mediaId: isset($data['media_id']) ? (string) $data['media_id'] : null,
            mediaMimeType: isset($data['media_mime_type']) ? (string) $data['media_mime_type'] : null,
            mediaUrl: isset($data['media_url']) ? (string) $data['media_url'] : null,
            latitude: isset($data['latitude']) ? (float) $data['latitude'] : null,
            longitude: isset($data['longitude']) ? (float) $data['longitude'] : null,
            interactiveReplyId: isset($data['interactive_reply_id']) ? (string) $data['interactive_reply_id'] : null,
            interactiveReplyTitle: isset($data['interactive_reply_title']) ? (string) $data['interactive_reply_title'] : null,
            rawPayload: (array) ($data['raw_payload'] ?? []),
        );
    }
}
