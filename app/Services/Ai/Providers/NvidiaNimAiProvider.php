<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Contracts\AiProviderContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * NVIDIA NIM Vision & Tool-Calling Provider (OpenAI-compatible Chat Completions API).
 * Fails closed when API key is missing, HTTP fails, or response JSON is invalid.
 */
final class NvidiaNimAiProvider implements AiProviderContract
{
    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function analyzeImageForDiagnosis(
        string $base64Image,
        string $mimeType,
        string $systemPrompt,
        string $userPrompt,
        array $context = []
    ): array {
        $apiKey = trim((string) config('betterlink.ai.nvidia_nim.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('NVIDIA NIM API key is not configured.');
        }

        $baseUrl = rtrim((string) config('betterlink.ai.nvidia_nim.base_url', 'https://integrate.api.nvidia.com/v1'), '/');
        $model = (string) config('betterlink.ai.nvidia_nim.vision_model', 'meta/llama-3.2-90b-vision-instruct');
        $maxTokens = (int) config('betterlink.ai.nvidia_nim.max_tokens', 2048);
        $timeout = (int) config('betterlink.ai.nvidia_nim.timeout', 60);

        $userContent = [
            ['type' => 'text', 'text' => $userPrompt],
        ];

        if ($base64Image !== '' && $mimeType !== 'text/plain') {
            $userContent[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mimeType};base64,{$base64Image}",
                ],
            ];
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->post("{$baseUrl}/chat/completions", [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => (float) config('betterlink.ai.nvidia_nim.temperature', 0.1),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userContent],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('NVIDIA NIM Vision API call failed with status ' . $response->status());
        }

        $textContent = (string) ($response->json('choices.0.message.content') ?? '{}');
        if (preg_match('/\{[\s\S]*\}/', $textContent, $matches) === 1) {
            $textContent = $matches[0];
        }

        $decoded = json_decode($textContent, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('NVIDIA NIM Vision API returned invalid JSON.');
        }

        return $decoded;
    }

    /**
     * @param list<array{role: string, content: mixed}> $messages
     * @param list<array<string, mixed>> $tools
     * @return array{text: string, tool_calls: list<array{id: string, name: string, input: array<string, mixed>}>, model: string, prompt_tokens: int, completion_tokens: int}
     */
    public function chatWithTools(
        string $systemPrompt,
        array $messages,
        array $tools = []
    ): array {
        $apiKey = trim((string) config('betterlink.ai.nvidia_nim.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('NVIDIA NIM API key is not configured.');
        }

        $baseUrl = rtrim((string) config('betterlink.ai.nvidia_nim.base_url', 'https://integrate.api.nvidia.com/v1'), '/');
        $model = (string) config('betterlink.ai.nvidia_nim.reasoning_model', 'meta/llama-3.3-70b-instruct');
        $maxTokens = (int) config('betterlink.ai.nvidia_nim.max_tokens', 2048);
        $timeout = (int) config('betterlink.ai.nvidia_nim.timeout', 60);

        $formattedMessages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($messages as $m) {
            $formattedMessages[] = [
                'role' => (string) ($m['role'] ?? 'user'),
                'content' => is_string($m['content'] ?? null)
                    ? $m['content']
                    : (string) json_encode($m['content']),
            ];
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => (float) config('betterlink.ai.nvidia_nim.temperature', 0.1),
            'messages' => $formattedMessages,
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(static function (array $tool): array {
                if (($tool['type'] ?? null) === 'function') {
                    return $tool;
                }

                return [
                    'type' => 'function',
                    'function' => [
                        'name' => (string) ($tool['name'] ?? ''),
                        'description' => (string) ($tool['description'] ?? ''),
                        'parameters' => (array) ($tool['input_schema'] ?? $tool['parameters'] ?? ['type' => 'object', 'properties' => (object) []]),
                    ],
                ];
            }, $tools);
            $payload['tool_choice'] = 'auto';
        }

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->post("{$baseUrl}/chat/completions", $payload);

        if (!$response->successful()) {
            throw new RuntimeException('NVIDIA NIM chatWithTools API call failed with status ' . $response->status());
        }

        $message = (array) $response->json('choices.0.message', []);
        $text = is_string($message['content'] ?? null) ? trim($message['content']) : '';

        $toolCalls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $tc) {
            $fn = (array) ($tc['function'] ?? []);
            $rawArgs = $fn['arguments'] ?? '{}';
            $parsedArgs = is_string($rawArgs) ? (json_decode($rawArgs, true) ?? []) : (array) $rawArgs;

            $toolCalls[] = [
                'id' => (string) ($tc['id'] ?? ''),
                'name' => (string) ($fn['name'] ?? ''),
                'input' => is_array($parsedArgs) ? $parsedArgs : [],
            ];
        }

        return [
            'text' => $text,
            'tool_calls' => $toolCalls,
            'model' => (string) $response->json('model', $model),
            'prompt_tokens' => (int) $response->json('usage.prompt_tokens', 0),
            'completion_tokens' => (int) $response->json('usage.completion_tokens', 0),
        ];
    }
}
