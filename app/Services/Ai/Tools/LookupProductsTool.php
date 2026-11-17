<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\DTOs\MatchedProductRecommendationData;
use App\Models\Conversation;
use App\Models\Crop;
use App\Models\Livestock;
use App\Services\Diagnosis\ProductMatcherService;

final class LookupProductsTool implements AiToolContract
{
    public function __construct(
        private readonly ProductMatcherService $productMatcher
    ) {
    }

    public function name(): string
    {
        return 'lookup_products';
    }

    public function description(): string
    {
        return 'Deterministically look up approved, Zimbabwe-registered products from the tenant catalogue for a specific crop/livestock and target pest/disease.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target_code' => ['type' => 'string', 'description' => 'Target pest or disease code (e.g. fall_armyworm, grey_leaf_spot)'],
                'crop_code' => ['type' => 'string', 'description' => 'Crop code (e.g. maize, tomatoes, soya_beans)'],
                'livestock_code' => ['type' => 'string', 'description' => 'Livestock code if animal health condition'],
            ],
            'required' => ['target_code'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $cropId = null;
        if (!empty($input['crop_code'])) {
            $cropId = Crop::query()->where('code', '=', (string) $input['crop_code'])->value('id');
        }

        $livestockId = null;
        if (!empty($input['livestock_code'])) {
            $livestockId = Livestock::query()->where('code', '=', (string) $input['livestock_code'])->value('id');
        }

        $products = $this->productMatcher->findApprovedProducts(
            tenant: $conversation->tenant,
            targetCode: (string) $input['target_code'],
            cropId: $cropId ? (string) $cropId : null,
            livestockId: $livestockId ? (string) $livestockId : null,
            limit: 3
        );

        return [
            'products' => array_map(fn (MatchedProductRecommendationData $p) => $p->toArray(), $products),
        ];
    }
}
