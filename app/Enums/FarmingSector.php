<?php

declare(strict_types=1);

namespace App\Enums;

enum FarmingSector: string
{
    case COMMUNAL = 'communal';
    case A1 = 'a1';
    case A2 = 'a2';
    case SMALL_SCALE_COMMERCIAL = 'small_scale_commercial';
    case LARGE_SCALE_COMMERCIAL = 'large_scale_commercial';

    public function label(): string
    {
        return match ($this) {
            self::COMMUNAL => 'Communal Area Farmer',
            self::A1 => 'A1 Resettlement Farmer',
            self::A2 => 'A2 Commercial Resettlement Farmer',
            self::SMALL_SCALE_COMMERCIAL => 'Small-Scale Commercial Farmer',
            self::LARGE_SCALE_COMMERCIAL => 'Large-Scale Commercial Estate',
        };
    }
}
