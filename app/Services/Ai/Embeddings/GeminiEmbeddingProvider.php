<?php

declare(strict_types=1);

namespace App\Services\Ai\Embeddings;

use App\Contracts\EmbeddingProviderContract;
use Illuminate\Support\Facades\Http;
use Throwable;

final class GeminiEmbeddingProvider implements EmbeddingProviderContract
{
    public function __construct(
        private readonly VoyageEmbeddingProvider $fallbackProvider
    ) {
    }

    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        $apiKey = trim((string) config('betterlink.ai.gemini.api_key', ''));
        $dimension = (int) config('betterlink.ai.vector_dimension', 1024);

        if ($apiKey !== '' && !str_starts_with($apiKey, 'AQ.')) {
            $baseUrl = rtrim((string) config('betterlink.ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta'), '/');
            $model = (string) config('betterlink.ai.gemini.embedding_model', 'text-embedding-004');

            try {
                $response = Http::timeout(20)->post(
                    "{$baseUrl}/models/{$model}:embedContent?key=" . urlencode($apiKey),
                    [
                        'model' => "models/{$model}",
                        'content' => [
                            'parts' => [['text' => $text]],
                        ],
                    ]
                );

                if ($response->successful()) {
                    /** @var list<float> $values */
                    $values = array_map('floatval', (array) $response->json('embedding.values', []));
                    if ($values !== []) {
                        return array_slice(array_pad($values, $dimension, 0.0), 0, $dimension);
                    }
                }
            } catch (Throwable) {
                // Fall back to deterministic embedding
            }
        }

        return $this->fallbackProvider->embedText($text);
    }
}
