<?php

declare(strict_types=1);

use App\Http\Middleware\AuthenticatePlannerFarmer;
use App\Http\Middleware\EnforceWhatsAppIdempotency;
use App\Http\Middleware\EnsureOutletManagerScope;
use App\Http\Middleware\ResolveTenantFromRequest;
use App\Http\Middleware\VerifyWhatsAppSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\EnsureProductionDatabaseExtensions::class);

        $middleware->alias([
            'whatsapp.signature' => VerifyWhatsAppSignature::class,
            'whatsapp.idempotency' => EnforceWhatsAppIdempotency::class,
            'tenant.resolve' => ResolveTenantFromRequest::class,
            'outlet.scope' => EnsureOutletManagerScope::class,
            'planner.auth' => AuthenticatePlannerFarmer::class,
        ]);

        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            AuthenticatePlannerFarmer::class
        );

        $middleware->throttleApi('120,1');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        if (class_exists(Integration::class)) {
            Integration::handles($exceptions);
        }
    })
    ->create();
