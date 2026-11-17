<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ConversationTurnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = (array) $this->resource;

        return [
            'conversation_id' => $data['conversation_id'],
            'state' => $data['state'],
            'mode' => $data['mode'],
            'language' => $data['language'],
            'context_chip' => $data['context_chip'],
            'reply_text' => $data['reply_text'],
            'buttons' => $data['buttons'] ?? [],
            'rich_cards' => $data['rich_cards'] ?? [],
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
