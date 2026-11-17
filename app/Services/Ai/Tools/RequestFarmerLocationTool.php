<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Enums\ConversationState;
use App\Models\Conversation;

final class RequestFarmerLocationTool implements AiToolContract
{
    public function name(): string
    {
        return 'request_farmer_location';
    }

    public function description(): string
    {
        return 'Transition conversation to AWAITING_LOCATION and ask the farmer for a WhatsApp location pin or their Ward/Business Centre.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'reason' => ['type' => 'string'],
            ],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $conversation->update([
            'state' => ConversationState::AWAITING_LOCATION,
            'state_updated_at' => now(),
        ]);

        return [
            'state' => ConversationState::AWAITING_LOCATION->value,
            'prompt_message' => 'Please share your WhatsApp Location Pin (📎 -> Location) or reply with your District & Ward / Business Centre name so we can find the nearest stocked outlet. (We only share your ward and distance band with outlets until you ask to be contacted.)',
        ];
    }
}
