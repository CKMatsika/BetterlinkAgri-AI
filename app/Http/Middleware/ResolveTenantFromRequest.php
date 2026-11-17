<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolveTenantFromRequest
{
    public function __construct(
        private readonly TenantContext $tenantContext
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && $user->tenant_id !== null) {
            $this->tenantContext->setTenantId((string) $user->tenant_id);

            return $next($request);
        }

        $tenantHeaderId = $request->header('X-Tenant-Id');
        if (is_string($tenantHeaderId) && $tenantHeaderId !== '') {
            $tenant = Tenant::query()
                ->where('id', '=', $tenantHeaderId)
                ->where('is_active', '=', true)
                ->first();

            if ($tenant === null) {
                abort(Response::HTTP_FORBIDDEN, 'Invalid or inactive tenant ID.');
            }

            $this->tenantContext->setTenant($tenant);

            return $next($request);
        }

        $tenantSlug = $request->header('X-Tenant-Slug') ?? $request->route('tenant');
        if (is_string($tenantSlug) && $tenantSlug !== '') {
            $tenant = Tenant::query()
                ->where('slug', '=', $tenantSlug)
                ->where('is_active', '=', true)
                ->first();

            if ($tenant === null) {
                abort(Response::HTTP_FORBIDDEN, 'Invalid or inactive tenant slug.');
            }

            $this->tenantContext->setTenant($tenant);

            return $next($request);
        }

        abort(Response::HTTP_BAD_REQUEST, 'Tenant context could not be resolved.');
    }
}
