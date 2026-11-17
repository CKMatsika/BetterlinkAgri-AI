<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Outlet;
use App\Models\Scopes\OutletScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Enforces that Outlet Managers only view or mutate records belonging to their assigned outlet(s).
 *
 * @mixin Model
 */
trait BelongsToOutlet
{
    public static function bootBelongsToOutlet(): void
    {
        static::addGlobalScope(new OutletScope());
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
