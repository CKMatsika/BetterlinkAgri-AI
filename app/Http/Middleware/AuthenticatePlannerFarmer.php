<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticatePlannerFarmer
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken()
            ?: (string) $request->query('token', '')
            ?: (string) $request->cookie('planner_token', '');

        if ($token === '') {
            return response()->json(['message' => 'Phone OTP authentication required.'], 401);
        }

        $session = Cache::get('planner_token:' . hash('sha256', $token));
        if (!is_array($session) || empty($session['farmer_id'])) {
            return response()->json(['message' => 'Invalid or expired planner session.'], 401);
        }

        $request->attributes->set('planner_farmer_id', (string) $session['farmer_id']);
        $request->attributes->set('planner_tenant_id', $session['tenant_id'] ?? null);

        if (!empty($session['tenant_id'])) {
            app(TenantContext::class)->setTenantId((string) $session['tenant_id']);
        }

        return $next($request);
    }
}
