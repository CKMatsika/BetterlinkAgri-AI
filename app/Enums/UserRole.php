<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case SUPER_ADMIN = 'super_admin';
    case TENANT_ADMIN = 'tenant_admin';
    case AGRONOMIST = 'agronomist';
    case SALES_REP = 'sales_rep';
    case OUTLET_MANAGER = 'outlet_manager';

    public function canAccessPlatformPanel(): bool
    {
        return $this === self::SUPER_ADMIN;
    }

    public function canAccessTenantPanel(): bool
    {
        return in_array($this, [
            self::SUPER_ADMIN,
            self::TENANT_ADMIN,
            self::AGRONOMIST,
            self::SALES_REP,
        ], true);
    }

    public function canAccessOutletPortal(): bool
    {
        return in_array($this, [
            self::SUPER_ADMIN,
            self::TENANT_ADMIN,
            self::OUTLET_MANAGER,
        ], true);
    }
}
