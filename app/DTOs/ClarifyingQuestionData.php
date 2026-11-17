<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class ClarifyingQuestionData
{
    /**
     * @param list<array{id: string, title: string, boosts_candidate?: string}> $options
     */
    public function __construct(
        public int $questionOrder,
        public string $questionKey,
        public string $questionText,
        public array $options,
        public bool $isMandatoryContext = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'question_order' => $this->questionOrder,
            'question_key' => $this->questionKey,
            'question_text' => $this->questionText,
            'options' => $this->options,
            'is_mandatory_context' => $this->isMandatoryContext,
        ];
    }
}
