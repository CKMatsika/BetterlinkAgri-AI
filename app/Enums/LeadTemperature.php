<?php

declare(strict_types=1);

namespace App\Enums;

enum LeadTemperature: string
{
    case COLD = 'cold';
    case WARM = 'warm';
    case HOT = 'hot';

    public function slaMinutes(): int
    {
        return match ($this) {
            self::HOT => (int) config('betterlink.slas.lead_hot_sla_minutes', 30),
            self::WARM => (int) config('betterlink.slas.lead_rep_sla_minutes', 120),
            self::COLD => 480,
        };
    }
}
