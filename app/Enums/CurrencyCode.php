<?php

declare(strict_types=1);

namespace App\Enums;

enum CurrencyCode: string
{
    case USD = 'USD';
    case ZIG = 'ZIG';

    public function symbol(): string
    {
        return match ($this) {
            self::USD => 'US$',
            self::ZIG => 'ZiG ',
        };
    }
}
