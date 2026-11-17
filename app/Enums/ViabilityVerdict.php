<?php

declare(strict_types=1);

namespace App\Enums;

enum ViabilityVerdict: string
{
    case VIABLE = 'viable';
    case VIABLE_WITH_CONDITIONS = 'viable_with_conditions';
    case MARGINAL = 'marginal';
    case NOT_VIABLE = 'not_viable';

    public function label(): string
    {
        return match ($this) {
            self::VIABLE => 'Viable',
            self::VIABLE_WITH_CONDITIONS => 'Viable with conditions',
            self::MARGINAL => 'Marginal',
            self::NOT_VIABLE => 'Not viable',
        };
    }
}
