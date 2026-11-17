<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\RichCardType;

final readonly class RichMessageCardData
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public RichCardType $cardType,
        public string $title,
        public string $contextChip,
        public array $payload,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'card_type' => $this->cardType->value,
            'title' => $this->title,
            'context_chip' => $this->contextChip,
            'payload' => $this->payload,
        ];
    }
}
