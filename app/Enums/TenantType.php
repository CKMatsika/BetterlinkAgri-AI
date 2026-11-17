<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantType: string
{
    case MANUFACTURER = 'manufacturer';
    case DISTRIBUTOR = 'distributor';
    case AGRO_DEALER = 'agro_dealer';

    public function label(): string
    {
        return match ($this) {
            self::MANUFACTURER => 'Manufacturer (Seed / Fertiliser / Agrochemical / Animal Health)',
            self::DISTRIBUTOR => 'Distributor / Wholesaler',
            self::AGRO_DEALER => 'Agro-Dealer / Retail Chain',
        };
    }
}
