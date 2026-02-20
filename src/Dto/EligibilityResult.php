<?php

namespace App\Dto;

final class EligibilityResult
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly string $reasonCode,
        public readonly string $reasonText,
        public readonly int $score,
        public readonly string $ruleVersion,
        public readonly array $metadata = [],
    ) {
    }
}
