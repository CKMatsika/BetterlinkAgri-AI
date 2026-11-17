<?php

declare(strict_types=1);

namespace App\Actions\Outlets;

use App\Enums\CurrencyCode;
use App\Enums\StockQuantityBand;
use App\Models\AuditLog;
use App\Models\Outlet;
use App\Models\OutletStock;
use App\Models\Product;

/**
 * Parses WhatsApp or quick-text stock updates from outlet managers, e.g.:
 * - "Compound D 50kg: 40 bags USD 32"
 * - "FERT-COMPOUND-D-50KG 50kg: 0 bags"
 * - "BeltGuard 100g: low ZiG 165.75"
 */
final class ParseWhatsAppStockUpdateAction
{
    /**
     * @return array{updated: bool, product_name?: string, pack_size?: string, quantity_band?: string, exact_quantity?: ?int, message: string}
     */
    public function execute(Outlet $outlet, string $rawText): array
    {
        $clean = trim($rawText);
        if (!str_contains($clean, ':')) {
            return [
                'updated' => false,
                'message' => 'Format not recognised. Send: "Product Name PackSize: Qty [Price]" (e.g. "Compound D 50kg: 40 bags USD 32").',
            ];
        }

        [$leftPart, $rightPart] = array_map('trim', explode(':', $clean, 2));

        // Extract pack size from left part (e.g. 50kg, 10kg, 250g, 100g, 1L, 250ml, 50ml)
        $packSize = '50kg';
        $productQueryTerm = $leftPart;
        if (preg_match('/\b(\d+(?:\.\d+)?\s*(?:kg|g|ml|l))\b/i', $leftPart, $m) === 1) {
            $packSize = strtolower(str_replace(' ', '', $m[1]));
            if (str_ends_with($packSize, 'l') && !str_ends_with($packSize, 'ml')) {
                $packSize = substr($packSize, 0, -1) . 'L';
            }
            $productQueryTerm = trim(str_replace($m[0], '', $leftPart));
        }

        $product = Product::withoutGlobalScopes()
            ->where('tenant_id', '=', $outlet->tenant_id)
            ->where(function ($q) use ($productQueryTerm): void {
                $q->where('sku', 'LIKE', "%{$productQueryTerm}%")
                    ->orWhere('name', 'LIKE', "%{$productQueryTerm}%");
            })
            ->first();

        if ($product === null) {
            return [
                'updated' => false,
                'message' => "Could not match product '{$productQueryTerm}' in catalogue.",
            ];
        }

        $exactQty = null;
        $band = StockQuantityBand::IN_STOCK;
        $lowerRight = strtolower($rightPart);

        if (str_contains($lowerRight, 'out') || preg_match('/\b0\s*(?:bags|packs|units|bottles)?\b/', $lowerRight) === 1) {
            $band = StockQuantityBand::OUT_OF_STOCK;
            $exactQty = 0;
        } elseif (str_contains($lowerRight, 'low')) {
            $band = StockQuantityBand::LOW_STOCK;
        } elseif (preg_match('/\b(\d+)\s*(?:bags|packs|units|bottles|tins)?\b/i', $rightPart, $qm) === 1) {
            $exactQty = (int) $qm[1];
            $band = $exactQty === 0
                ? StockQuantityBand::OUT_OF_STOCK
                : ($exactQty <= 10 ? StockQuantityBand::LOW_STOCK : StockQuantityBand::IN_STOCK);
        }

        $currency = str_contains(strtoupper($rightPart), 'ZIG') ? CurrencyCode::ZIG : CurrencyCode::USD;
        $price = null;
        if (preg_match('/(?:USD|US\$|ZIG)\s*(\d+(?:\.\d+)?)/i', $rightPart, $pm) === 1) {
            $price = (float) $pm[1];
        }

        $existing = OutletStock::withoutGlobalScopes()
            ->where('outlet_id', '=', $outlet->id)
            ->where('product_id', '=', $product->id)
            ->where('currency', '=', $currency->value)
            ->first();

        $resolvedPrice = $price ?? ($existing !== null ? (float) $existing->price : 25.00);

        $stock = OutletStock::withoutGlobalScopes()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'product_id' => $product->id,
                'pack_size' => $packSize,
                'currency' => $currency->value,
            ],
            [
                'tenant_id' => $outlet->tenant_id,
                'quantity_band' => $band,
                'exact_quantity' => $exactQty,
                'price' => $resolvedPrice,
                'source' => 'whatsapp_bot',
                'last_updated_at' => now(),
            ]
        );

        AuditLog::query()->create([
            'tenant_id' => $outlet->tenant_id,
            'event_type' => 'outlet_stock_updated',
            'metadata_json' => [
                'outlet_id' => $outlet->id,
                'product_id' => $product->id,
                'pack_size' => $stock->pack_size,
                'quantity_band' => $band->value,
                'exact_quantity' => $exactQty,
                'source' => 'whatsapp_bot',
            ],
            'created_at' => now(),
        ]);

        return [
            'updated' => true,
            'product_name' => $product->name,
            'pack_size' => $stock->pack_size,
            'quantity_band' => $band->value,
            'exact_quantity' => $exactQty,
            'message' => "Updated {$product->name} ({$stock->pack_size}) at {$outlet->name}: {$band->value}"
                . ($exactQty !== null ? " ({$exactQty} units)" : '')
                . " @ {$currency->value} {$resolvedPrice}.",
        ];
    }
}
