<?php

declare(strict_types=1);

namespace App\Enums;

enum RecommendationScope: string
{
    case OWN_BRANDS_ONLY = 'own_brands_only';
    case OWN_PLUS_PARTNER_BRANDS = 'own_plus_partner_brands';

    public function label(): string
    {
        return match ($this) {
            self::OWN_BRANDS_ONLY => 'Own Approved Brands Only',
            self::OWN_PLUS_PARTNER_BRANDS => 'Own + Authorised Supply-Link Partner Brands',
        };
    }
}
