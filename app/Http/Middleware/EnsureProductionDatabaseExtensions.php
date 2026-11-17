<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ProductionDatabaseGuard;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureProductionDatabaseExtensions
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/v1/health')) {
            ProductionDatabaseGuard::assertReady();
        }

        return $next($request);
    }
}
