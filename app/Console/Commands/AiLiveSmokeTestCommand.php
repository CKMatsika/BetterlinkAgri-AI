<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\AiProviderContract;
use App\Contracts\EmbeddingProviderContract;
use Illuminate\Console\Command;

final class AiLiveSmokeTestCommand extends Command
{
    protected $signature = 'ai:live-smoke {--with-embeddings : Also verify 1024-dim embedding generation}';

    protected $description = 'Run a live AI smoke test (1 crop photo diagnosis + 1 planner turn) without printing secrets.';

    public function handle(AiProviderContract $ai, EmbeddingProviderContract $embeddings): int
    {
        $provider = strtolower((string) config('betterlink.ai.default_provider', ''));
        if ($provider === 'fake') {
            $this->error('AI_DEFAULT_PROVIDER=fake cannot be used for a live smoke test.');

            return self::FAILURE;
        }

        $this->info("Running live AI smoke test with provider: {$provider}");

        // 1. Planner interview turn
        try {
            $chat = $ai->chatWithTools(
                systemPrompt: 'You are a Zimbabwe agricultural farm planning advisor. Respond in one concise sentence.',
                messages: [
                    ['role' => 'user', 'content' => 'I want to start 500 broilers in Harare next month. Ask me the first planning question.'],
                ]
            );
            $this->info('[PASS] Planner turn (' . $chat['model'] . '): ' . $chat['text']);
        } catch (\Throwable $e) {
            $this->error('[FAIL] Planner turn: ' . $e->getMessage());

            return self::FAILURE;
        }

        // 2. Crop photo diagnosis (400x400 synthetic leaf JPEG)
        try {
            $img = imagecreatetruecolor(400, 400);
            $green = imagecolorallocate($img, 42, 130, 48);
            $lesion = imagecolorallocate($img, 165, 110, 35);
            imagefill($img, 0, 0, $green);
            imagefilledellipse($img, 200, 200, 70, 40, $lesion);
            ob_start();
            imagejpeg($img, null, 85);
            $jpeg = (string) ob_get_clean();
            imagedestroy($img);

            $vision = $ai->analyzeImageForDiagnosis(
                base64Image: base64_encode($jpeg),
                mimeType: 'image/jpeg',
                systemPrompt: 'You are a Zimbabwe crop pathologist. Return strict JSON with keys: category, crop, summary.',
                userPrompt: 'Inspect this sample image and return a JSON object with category, crop, and a 1-sentence summary.'
            );
            $this->info('[PASS] Vision diagnosis JSON: ' . json_encode($vision));
        } catch (\Throwable $e) {
            $this->error('[FAIL] Vision diagnosis: ' . $e->getMessage());

            return self::FAILURE;
        }

        // 3. Optional 1024-dim embedding check
        if ($this->option('with-embeddings')) {
            try {
                $vec = $embeddings->embedText('Compound D basal fertiliser application for Pfumvudza maize in Zimbabwe');
                $this->info('[PASS] Embedding generated: ' . count($vec) . ' dimensions (configured PGVECTOR_DIMENSION=' . config('betterlink.ai.vector_dimension') . ')');
            } catch (\Throwable $e) {
                $this->error('[FAIL] Embeddings: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info('Live AI smoke test PASSED.');

        return self::SUCCESS;
    }
}
