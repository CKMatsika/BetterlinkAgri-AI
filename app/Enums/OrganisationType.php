<?php

declare(strict_types=1);

namespace App\Enums;

enum OrganisationType: string
{
    case MANUFACTURER = 'manufacturer';
    case DISTRIBUTOR = 'distributor';
    case RETAILER = 'retailer';
    case BANK = 'bank';
    case GOVERNMENT_OFFICE = 'government_office';
    case INDEPENDENT_RETAILER = 'independent_retailer';
    case OFFTAKER = 'offtaker';

    public function isRetailSeller(): bool
    {
        return $this === self::RETAILER || $this === self::INDEPENDENT_RETAILER;
    }

    public function isAggregateOnlyActor(): bool
    {
        return $this === self::MANUFACTURER || $this === self::GOVERNMENT_OFFICE;
    }
}
