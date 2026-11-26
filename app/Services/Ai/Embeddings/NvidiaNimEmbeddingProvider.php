<?php

declare(strict_types=1);

namespace App\Services\Ai\Embeddings;

use App\Contracts\EmbeddingProviderContract;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * NVIDIA NIM Embeddings Provider (nvidia/nv-embedqa-e5-v5 -> 1024 dimensions, matches PGVECTOR_DIMENSION=1024).
 */
final class NvidiaNimEmbeddingProvider implements EmbeddingProviderContract
{
    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        $apiKey = trim((string) config('betterlink.ai.nvidia_nim.api_key', ''));
        if ($apiKey === '') {
            throw new RuntimeException('NVIDIA NIM API key is not configured for embeddings.');
        }

        $baseUrl = rtrim((string) config('betterlink.ai.nvidia_nim.base_url', 'https://integrate.api.nvidia.com/v1'), '/');
        $model = (string) config('betterlink.ai.nvidia_nim.embedding_model', 'nvidia/nv-embedqa-e5-v5');
        $timeout = (int) config('betterlink.ai.nvidia_nim.timeout', 60);

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->post("{$baseUrl}/embeddings", [
                'model' => $model,
                'input' => [$text],
                'input_type' => 'query',
                'truncate' => 'END',
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('NVIDIA NIM Embeddings API call failed with status ' . $response->status());
        }

        $vector = $response->json('data.0.embedding');
        if (!is_array($vector) || $vector === []) {
            throw new RuntimeException('NVIDIA NIM Embeddings API returned an empty vector.');
        }

        return array_values(array_map('floatval', $vector));
    }
}
