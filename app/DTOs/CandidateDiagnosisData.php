<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CandidateDiagnosisData
{
    /**
     * @param array{sn: string, nd: string} $localNames
     * @param list<string> $visibleEvidence
     * @param list<string> $lookAlikes
     */
    public function __construct(
        public string $code,
        public string $name,
        public array $localNames,
        public string $type,
        public float $confidence,
        public array $visibleEvidence = [],
        public array $lookAlikes = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'local_names' => $this->localNames,
            'type' => $this->type,
            'confidence' => round($this->confidence, 4),
            'visible_evidence' => $this->visibleEvidence,
            'look_alikes' => $this->lookAlikes,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            code: (string) ($data['code'] ?? strtolower(str_replace(' ', '_', (string) ($data['name'] ?? 'unknown')))),
            name: (string) ($data['name'] ?? 'Unknown'),
            localNames: [
                'sn' => (string) ($data['local_names']['sn'] ?? ''),
                'nd' => (string) ($data['local_names']['nd'] ?? ''),
            ],
            type: (string) ($data['type'] ?? 'pest'),
            confidence: (float) ($data['confidence'] ?? 0.0),
            visibleEvidence: array_values(array_map('strval', (array) ($data['visible_evidence'] ?? []))),
            lookAlikes: array_values(array_map('strval', (array) ($data['look_alikes'] ?? []))),
        );
    }
}
