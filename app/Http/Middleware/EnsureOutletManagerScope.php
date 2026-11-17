<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureOutletManagerScope
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if ($user->role === UserRole::OUTLET_MANAGER) {
            /** @var list<string> $outletIds */
            $outletIds = $user->outlets()->pluck('outlets.id')->map(fn ($id) => (string) $id)->all();

            if ($outletIds === []) {
                abort(Response::HTTP_FORBIDDEN, 'Outlet manager is not assigned to any active outlet.');
            }

            $this->tenantContext->setOutletIds($outletIds);
        }

        return $next($request);
    }
}
