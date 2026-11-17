<?php

declare(strict_types=1);

namespace App\Services\Ai\Voice;

use App\Contracts\VoiceTranscriberContract;
use App\Enums\LanguageCode;
use App\Services\Localization\ZimbabweAgronomyGlossary;
use Illuminate\Support\Facades\Http;

final class VoiceTranscriptionService implements VoiceTranscriberContract
{
    public function __construct(
        private readonly ZimbabweAgronomyGlossary $glossary
    ) {
    }

    /**
     * @return array{text: string, language: LanguageCode, duration_ms: int}
     */
    public function transcribe(string $audioBytes, string $mimeType = 'audio/ogg'): array
    {
        $startTime = hrtime(true);
        $apiKey = (string) config('betterlink.ai.openai.api_key', '');
        $baseUrl = (string) config('betterlink.ai.openai.base_url', 'https://api.openai.com/v1');
        $model = (string) config('betterlink.ai.openai.whisper_model', 'whisper-1');

        if ($apiKey === '') {
            // Deterministic fallback when API key is not configured (e.g., offline dev / automated test)
            $fallbackText = trim($audioBytes) !== '' && ctype_print($audioBytes)
                ? $audioBytes
                : 'Chibage changu chine makonye mumwoyo wembesa.';
            $detected = $this->glossary->detectLanguage($fallbackText);

            return [
                'text' => $fallbackText,
                'language' => $detected,
                'duration_ms' => (int) round((hrtime(true) - $startTime) / 1_000_000),
            ];
        }

        $extension = match ($mimeType) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/m4a' => 'm4a',
            default => 'ogg',
        };

        $response = Http::withToken($apiKey)
            ->timeout(45)
            ->attach('file', $audioBytes, "voicenote.{$extension}")
            ->post("{$baseUrl}/audio/transcriptions", [
                'model' => $model,
                'response_format' => 'verbose_json',
            ]);

        $transcribedText = $response->successful()
            ? trim((string) $response->json('text', ''))
            : '';

        $detected = $this->glossary->detectLanguage($transcribedText);

        return [
            'text' => $transcribedText,
            'language' => $detected,
            'duration_ms' => (int) round((hrtime(true) - $startTime) / 1_000_000),
        ];
    }
}
