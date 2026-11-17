<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyWhatsAppSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        $appSecret = (string) config('betterlink.whatsapp.app_secret', '');

        if ($appSecret === '') {
            abort(Response::HTTP_INTERNAL_SERVER_ERROR, 'WhatsApp webhook app secret is not configured.');
        }

        $signatureHeader = (string) $request->header('X-Hub-Signature-256', '');

        if (!str_starts_with($signatureHeader, 'sha256=')) {
            abort(Response::HTTP_UNAUTHORIZED, 'Missing or malformed X-Hub-Signature-256 header.');
        }

        $providedHash = substr($signatureHeader, 7);
        $expectedHash = hash_hmac('sha256', $request->getContent(), $appSecret);

        if (!hash_equals($expectedHash, $providedHash)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Invalid WhatsApp webhook signature.');
        }

        return $next($request);
    }
}
