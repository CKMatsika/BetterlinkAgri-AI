<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Jobs\ProcessInboundWhatsAppMessageJob;
use App\Services\Channels\WhatsAppCloudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class WhatsAppWebhookController
{
    public function __construct(
        private readonly WhatsAppCloudService $whatsAppService
    ) {
    }

    /**
     * Handle Meta WhatsApp Cloud API GET verification challenge.
     */
    public function verify(Request $request): Response
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        $expectedToken = (string) config('betterlink.whatsapp.verify_token', '');

        if ($mode === 'subscribe' && $expectedToken !== '' && hash_equals($expectedToken, $token)) {
            return response($challenge, Response::HTTP_OK)
                ->header('Content-Type', 'text/plain');
        }

        return response('Forbidden: Invalid verification token.', Response::HTTP_FORBIDDEN);
    }

    /**
     * Handle Meta WhatsApp Cloud API POST inbound messages & status updates.
     */
    public function handle(Request $request): JsonResponse
    {
        $inboundMessages = $this->whatsAppService->parseWebhookPayload($request->all());

        foreach ($inboundMessages as $inboundDto) {
            ProcessInboundWhatsAppMessageJob::dispatch($inboundDto->toArray());
        }

        return response()->json([
            'status' => 'accepted',
            'queued_messages' => count($inboundMessages),
        ], Response::HTTP_OK);
    }
}
