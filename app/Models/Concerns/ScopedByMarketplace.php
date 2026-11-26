<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\MarketplaceScope;

trait ScopedByMarketplace
{
    protected static function bootScopedByMarketplace(): void
    {
        static::addGlobalScope(new MarketplaceScope());
    }
}
