<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Contracts\AiProviderContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anthropic Claude Vision & Tool-Calling Provider (AiProviderContract).
 * Fails closed when API key is missing, HTTP fails, or response JSON is invalid.
 */
final class AnthropicClaudeProvider implements AiProviderContract
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
        $apiKey = trim((string) config('betterlink.ai.anthropic.api_key', ''));

        if ($apiKey === '') {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        $baseUrl = (string) config('betterlink.ai.anthropic.base_url', 'https://api.anthropic.com/v1');
        $apiVersion = (string) config('betterlink.ai.anthropic.api_version', '2023-06-01');
        $model = (string) config('betterlink.ai.anthropic.vision_model', 'claude-sonnet-5-5');
        $maxTokens = (int) config('betterlink.ai.anthropic.max_tokens', 2048);
        $timeout = (int) config('betterlink.ai.anthropic.timeout', 45);

        $contentParts = [];
        if ($base64Image !== '' && $mimeType !== 'text/plain') {
            $contentParts[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $mimeType,
                    'data' => $base64Image,
                ],
            ];
        }
        $contentParts[] = [
            'type' => 'text',
            'text' => $userPrompt,
        ];

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => $apiVersion,
            'content-type' => 'application/json',
        ])
            ->timeout($timeout)
            ->post("{$baseUrl}/messages", [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'temperature' => (float) config('betterlink.ai.anthropic.temperature', 0.1),
                'system' => $systemPrompt,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $contentParts,
                    ],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Anthropic Claude Vision API call failed with status ' . $response->status());
        }

        $textContent = (string) ($response->json('content.0.text') ?? '{}');

        if (preg_match('/\{[\s\S]*\}/', $textContent, $matches) === 1) {
            $textContent = $matches[0];
        }

        $decoded = json_decode($textContent, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Anthropic Claude Vision API returned invalid JSON.');
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
        $apiKey = trim((string) config('betterlink.ai.anthropic.api_key', ''));
        $model = (string) config('betterlink.ai.anthropic.reasoning_model', 'claude-sonnet-5-5');

        if ($apiKey === '') {
            throw new RuntimeException('Anthropic API key is not configured.');
        }

        $baseUrl = (string) config('betterlink.ai.anthropic.base_url', 'https://api.anthropic.com/v1');
        $apiVersion = (string) config('betterlink.ai.anthropic.api_version', '2023-06-01');
        $maxTokens = (int) config('betterlink.ai.anthropic.max_tokens', 2048);
        $timeout = (int) config('betterlink.ai.anthropic.timeout', 45);

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'temperature' => (float) config('betterlink.ai.anthropic.temperature', 0.1),
            'system' => $systemPrompt,
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => $apiVersion,
            'content-type' => 'application/json',
        ])
            ->timeout($timeout)
            ->post("{$baseUrl}/messages", $payload);

        if (!$response->successful()) {
            throw new RuntimeException('Anthropic Claude chatWithTools call failed with status ' . $response->status());
        }

        $contentBlocks = (array) $response->json('content', []);
        $textParts = [];
        $toolCalls = [];

        foreach ($contentBlocks as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $textParts[] = (string) ($block['text'] ?? '');
            } elseif ($type === 'tool_use') {
                $toolCalls[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => (array) ($block['input'] ?? []),
                ];
            }
        }

        return [
            'text' => trim(implode("\n", $textParts)),
            'tool_calls' => $toolCalls,
            'model' => (string) $response->json('model', $model),
            'prompt_tokens' => (int) $response->json('usage.input_tokens', 0),
            'completion_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
    }
}
