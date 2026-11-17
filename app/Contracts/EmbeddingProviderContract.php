<?php

declare(strict_types=1);

namespace App\Contracts;

interface EmbeddingProviderContract
{
    /**
     * Generate a normalized float vector embedding for the given text.
     *
     * @return list<float>
     */
    public function embedText(string $text): array;
}
