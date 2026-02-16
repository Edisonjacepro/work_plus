<?php

namespace App\Dto;

class ImpactScoreResult
{
    public function __construct(
        public readonly int $societyScore,
        public readonly int $biodiversityScore,
        public readonly int $ghgScore,
        public readonly int $totalScore,
        public readonly float $confidence,
        public readonly string $ruleVersion,
        public readonly array $evidence,
    ) {
    }
}
