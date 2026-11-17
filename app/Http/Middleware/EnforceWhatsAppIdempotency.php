<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class EnforceWhatsAppIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        $payload = $request->all();
        $messageIds = $this->extractMessageIds($payload);

        if ($messageIds === []) {
            return $next($request);
        }

        $ttlSeconds = (int) config('betterlink.whatsapp.idempotency_ttl_seconds', 86400);
        $allDuplicates = true;

        foreach ($messageIds as $msgId) {
            $cacheKey = "whatsapp_idempotency:{$msgId}";
            if (Cache::add($cacheKey, true, $ttlSeconds)) {
                $allDuplicates = false;
            }
        }

        if ($allDuplicates) {
            return response()->json([
                'status' => 'duplicate_ignored',
                'message_ids' => $messageIds,
            ], Response::HTTP_OK);
        }

        return $next($request);
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function extractMessageIds(array $payload): array
    {
        $ids = [];
        $entries = (array) ($payload['entry'] ?? []);

        foreach ($entries as $entry) {
            $changes = (array) ($entry['changes'] ?? []);
            foreach ($changes as $change) {
                $messages = (array) ($change['value']['messages'] ?? []);
                foreach ($messages as $message) {
                    $id = (string) ($message['id'] ?? '');
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
