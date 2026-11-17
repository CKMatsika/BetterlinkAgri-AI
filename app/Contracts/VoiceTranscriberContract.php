<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Enums\LanguageCode;

interface VoiceTranscriberContract
{
    /**
     * Transcribe raw audio bytes (WhatsApp voice note) and detect language (en, sn, nd).
     *
     * @return array{text: string, language: LanguageCode, duration_ms: int}
     */
    public function transcribe(string $audioBytes, string $mimeType = 'audio/ogg'): array;
}
