<?php

declare(strict_types=1);

namespace App\Enums;

enum StockQuantityBand: string
{
    case IN_STOCK = 'in_stock';
    case LOW_STOCK = 'low_stock';
    case OUT_OF_STOCK = 'out_of_stock';

    public function confidenceScore(): float
    {
        return match ($this) {
            self::IN_STOCK => 1.0,
            self::LOW_STOCK => 0.55,
            self::OUT_OF_STOCK => 0.0,
        };
    }
}
