<?php

declare(strict_types=1);

namespace App\Enums;

enum LanguageCode: string
{
    case ENGLISH = 'en';
    case SHONA = 'sn';
    case NDEBELE = 'nd';

    public function label(): string
    {
        return match ($this) {
            self::ENGLISH => 'English',
            self::SHONA => 'ChiShona',
            self::NDEBELE => 'isiNdebele',
        };
    }
}
