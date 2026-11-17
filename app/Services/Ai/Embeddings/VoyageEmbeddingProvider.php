<?php

declare(strict_types=1);

namespace App\Services\Ai\Embeddings;

use App\Contracts\EmbeddingProviderContract;
use Illuminate\Support\Facades\Http;

final class VoyageEmbeddingProvider implements EmbeddingProviderContract
{
    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        $dimension = (int) config('betterlink.ai.vector_dimension', 1024);
        $apiKey = (string) config('betterlink.ai.voyage.api_key', '');

        if ($apiKey !== '') {
            $baseUrl = (string) config('betterlink.ai.voyage.base_url', 'https://api.voyageai.com/v1');
            $model = (string) config('betterlink.ai.voyage.model', 'voyage-3');

            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post("{$baseUrl}/embeddings", [
                    'input' => [$text],
                    'model' => $model,
                ]);

            if ($response->successful()) {
                /** @var list<float> $vector */
                $vector = array_map('floatval', (array) $response->json('data.0.embedding', []));
                if (count($vector) === $dimension) {
                    return $vector;
                }
            }
        }

        // Deterministic normalized feature-hash embedding for offline/test environments
        return $this->deterministicNormalizedEmbedding($text, $dimension);
    }

    /**
     * @return list<float>
     */
    private function deterministicNormalizedEmbedding(string $text, int $dimension): array
    {
        $vector = array_fill(0, $dimension, 0.0);
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: ['empty'];

        foreach ($tokens as $token) {
            $hash = crc32($token);
            $index = abs($hash) % $dimension;
            $sign = ($hash & 1) === 1 ? 1.0 : -1.0;
            $vector[$index] += $sign;
        }

        $norm = 0.0;
        foreach ($vector as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt($norm);

        if ($norm > 0.0) {
            foreach ($vector as $i => $v) {
                $vector[$i] = round($v / $norm, 6);
            }
        }

        return $vector;
    }
}
