<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Contracts\AiToolContract;
use App\Models\Conversation;
use App\Models\OutletStock;

final class CheckOutletStockTool implements AiToolContract
{
    public function name(): string
    {
        return 'check_outlet_stock';
    }

    public function description(): string
    {
        return 'Check live stock band, USD/ZiG price, and 48-hour freshness status for a product at a specific outlet.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'outlet_id' => ['type' => 'string'],
                'product_id' => ['type' => 'string'],
            ],
            'required' => ['outlet_id', 'product_id'],
        ];
    }

    public function execute(Conversation $conversation, array $input): array
    {
        $freshnessLimit = $conversation->tenant->stockFreshnessLimitHours();

        $stocks = OutletStock::withoutGlobalScopes()
            ->where('outlet_id', '=', (string) $input['outlet_id'])
            ->where('product_id', '=', (string) $input['product_id'])
            ->get();

        if ($stocks->isEmpty()) {
            return ['in_stock' => false, 'items' => []];
        }

        $items = [];
        foreach ($stocks as $stock) {
            $isFresh = $stock->isFresh($freshnessLimit);
            $items[] = [
                'pack_size' => $stock->pack_size,
                'quantity_band' => $stock->quantity_band->value,
                'price' => (float) $stock->price,
                'currency' => $stock->currency->value,
                'is_fresh' => $isFresh,
                'last_updated_at' => $stock->last_updated_at?->toIso8601String(),
                'stale_warning' => $isFresh ? null : 'Stock data older than 48h — please call to confirm.',
            ];
        }

        return [
            'in_stock' => true,
            'items' => $items,
        ];
    }
}
