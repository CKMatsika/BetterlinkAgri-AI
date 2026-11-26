<?php

declare(strict_types=1);

namespace App\Services\Ai\Embeddings;

use App\Contracts\EmbeddingProviderContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mistral AI Embeddings Provider (mistral-embed -> 1024 dimensions, matches PGVECTOR_DIMENSION=1024).
 */
final class MistralEmbeddingProvider implements EmbeddingProviderContract
{
    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        $apiKey = trim((string) config('betterlink.ai.mistral.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('Mistral API key is not configured for embeddings.');
        }

        $baseUrl = rtrim((string) config('betterlink.ai.mistral.base_url', 'https://api.mistral.ai/v1'), '/');
        $model = (string) config('betterlink.ai.mistral.embedding_model', 'mistral-embed');
        $timeout = (int) config('betterlink.ai.mistral.timeout', 45);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->post("{$baseUrl}/embeddings", [
                'model' => $model,
                'input' => [$text],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Mistral Embeddings API call failed with status ' . $response->status());
        }

        $vector = $response->json('data.0.embedding');
        if (!is_array($vector) || $vector === []) {
            throw new RuntimeException('Mistral Embeddings API returned an empty vector.');
        }

        return array_values(array_map('floatval', $vector));
    }
}
