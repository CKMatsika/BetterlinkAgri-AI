<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class GuardrailValidationResultData
{
    /**
     * @param list<string> $violations
     * @param list<string> $verifiedSkus
     */
    public function __construct(
        public bool $passed,
        public bool $escalateNoRegisteredProduct,
        public string $mandatorySafetyDisclaimer,
        public array $violations = [],
        public array $verifiedSkus = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'escalate_no_registered_product' => $this->escalateNoRegisteredProduct,
            'mandatory_safety_disclaimer' => $this->mandatorySafetyDisclaimer,
            'violations' => $this->violations,
            'verified_skus' => $this->verifiedSkus,
        ];
    }
}
