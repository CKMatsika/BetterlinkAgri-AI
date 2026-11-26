<?php

declare(strict_types=1);

use App\Models\MarketplaceRequest;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('marketplace.outlet.{outletId}', function (User $user, string $outletId): bool {
    $outlet = Outlet::withoutGlobalScopes()->find($outletId);

    return $outlet !== null && $user->tenant_id === $outlet->tenant_id;
});

Broadcast::channel('marketplace.request.{requestId}', function ($actor, string $requestId): bool {
    $request = MarketplaceRequest::withoutGlobalScopes()->find($requestId);
    if ($request === null) {
        return false;
    }

    return $actor instanceof \App\Models\Farmer && $request->farmer_id === $actor->id;
});
