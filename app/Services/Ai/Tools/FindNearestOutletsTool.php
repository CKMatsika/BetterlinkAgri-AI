<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\DTOs\RankedOutletResultData;
use App\Models\Conversation;
use App\Services\Outlets\OutletFinderService;

final class FindNearestOutletsTool implements AiToolContract
{
    public function __construct(
        private readonly OutletFinderService $outletFinder
    ) {
    }

    public function name(): string
    {
        return 'find_nearest_outlets';
    }

    public function description(): string
    {
        return 'Find and rank the top 3 nearest authorised outlets with live stock for the recommended product IDs (expanding 25->50->100km->province).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'List of Product UUIDs in the farmer basket',
                ],
            ],
            'required' => ['product_ids'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $farmer = $conversation->farmer;
        if ($farmer === null) {
            return ['error' => 'No farmer linked to conversation'];
        }

        /** @var list<string> $productIds */
        $productIds = array_values(array_map('strval', (array) ($input['product_ids'] ?? [])));

        $result = $this->outletFinder->findNearestStockedOutlets(
            tenant: $conversation->tenant,
            farmer: $farmer,
            productIds: $productIds,
            conversationId: $conversation->id
        );

        return [
            'radius_used_km' => $result['radius_used_km'],
            'fallback_required' => $result['fallback_required'],
            'outlets' => array_map(fn (RankedOutletResultData $o) => $o->toArray(), $result['outlets']),
        ];
    }
}
