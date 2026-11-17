<?php

declare(strict_types=1);

namespace App\Enums;

enum HandoffType: string
{
    case SALES_REP = 'sales_rep';
    case OUTLET_MANAGER = 'outlet_manager';
    case AGRONOMIST = 'agronomist';
    case VET = 'vet';
}
