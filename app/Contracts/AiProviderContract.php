<?php

declare(strict_types=1);

namespace App\Contracts;

interface AiProviderContract
{
    /**
     * Perform multimodal vision analysis on an image and return structured JSON.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function analyzeImageForDiagnosis(
        string $base64Image,
        string $mimeType,
        string $systemPrompt,
        string $userPrompt,
        array $context = []
    ): array;

    /**
     * Execute a reasoning/chat turn with structured tool definitions.
     *
     * @param list<array{role: string, content: mixed}> $messages
     * @param list<array<string, mixed>> $tools
     * @return array{text: string, tool_calls: list<array{id: string, name: string, input: array<string, mixed>}>, model: string, prompt_tokens: int, completion_tokens: int}
     */
    public function chatWithTools(
        string $systemPrompt,
        array $messages,
        array $tools = []
    ): array;
}
