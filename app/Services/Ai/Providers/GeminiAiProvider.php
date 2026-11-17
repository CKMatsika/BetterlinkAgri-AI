<?php

declare(strict_types=1);

namespace App\Services\Ai\Providers;

use App\Contracts\AiProviderContract;
use RuntimeException;

/**
 * Gemini provider is currently disabled / on hold. Fails closed without fallback.
 */
final class GeminiAiProvider implements AiProviderContract
{
    public function analyzeImageForDiagnosis(
        string $base64Image,
        string $mimeType,
        string $systemPrompt,
        string $userPrompt,
        array $context = []
    ): array {
        throw new RuntimeException('Gemini AI provider is disabled.');
    }

    public function chatWithTools(
        string $systemPrompt,
        array $messages,
        array $tools = []
    ): array {
        throw new RuntimeException('Gemini AI provider is disabled.');
    }
}
