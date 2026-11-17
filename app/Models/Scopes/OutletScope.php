<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class OutletScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        /** @var TenantContext $context */
        $context = app(TenantContext::class);

        if ($context->isBypassingScope() || !$context->hasOutletRestriction()) {
            return;
        }

        $builder->whereIn($model->qualifyColumn('outlet_id'), $context->getOutletIds());
    }
}
