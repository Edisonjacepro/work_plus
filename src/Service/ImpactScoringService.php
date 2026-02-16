<?php

namespace App\Service;

use App\Dto\ImpactScoreResult;
use App\Entity\ImpactScore;
use App\Entity\Offer;

class ImpactScoringService
{
    public function __construct(private readonly ImpactEvidenceProviderInterface $evidenceProvider)
    {
    }

    public function score(Offer $offer): ImpactScoreResult
    {
        $evidence = $this->evidenceProvider->collectForOffer($offer);
        $axisScores = $this->computeScoresFromEvidence($evidence, $offer);
        $confidence = $this->computeConfidence($evidence);

        $rawTotal = ($axisScores['society'] * 0.4)
            + ($axisScores['biodiversity'] * 0.3)
            + ($axisScores['ghg'] * 0.3);
        $totalScore = (int) round($rawTotal * $confidence);
        $totalScore = max(0, min(100, $totalScore));

        return new ImpactScoreResult(
            societyScore: $axisScores['society'],
            biodiversityScore: $axisScores['biodiversity'],
            ghgScore: $axisScores['ghg'],
            totalScore: $totalScore,
            confidence: $confidence,
            ruleVersion: ImpactScore::RULE_VERSION_V1_AUTO,
            evidence: $evidence,
        );
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function computeScoresFromEvidence(array $evidence, Offer $offer): array
    {
        $scores = [
            'society' => 0,
            'biodiversity' => 0,
            'ghg' => 0,
        ];

        $company = is_array($evidence['company'] ?? null) ? $evidence['company'] : [];
        $location = is_array($evidence['location'] ?? null) ? $evidence['location'] : [];

        if (true === ($company['active'] ?? false)) {
            $scores['society'] += 15;
        }
        if (true === ($company['isEss'] ?? false)) {
            $scores['society'] += 40;
        }
        if (true === ($company['isMissionCompany'] ?? false)) {
            $scores['society'] += 25;
        }
        if (true === ($location['validated'] ?? false)) {
            $scores['society'] += 10;
            $scores['biodiversity'] += 10;
        }
        if (true === ($company['hasGesReport'] ?? false)) {
            $scores['ghg'] += 40;
        }

        $declaredCategories = array_map(
            static fn (mixed $category): string => mb_strtolower((string) $category, 'UTF-8'),
            $offer->getImpactCategories()
        );

        if (in_array('ges', $declaredCategories, true) && true === ($company['hasGesReport'] ?? false)) {
            $scores['ghg'] += 20;
        }
        if (in_array('societe', $declaredCategories, true) && true === ($company['isEss'] ?? false)) {
            $scores['society'] += 10;
        }
        if (in_array('biodiversite', $declaredCategories, true) && true === ($location['validated'] ?? false)) {
            $scores['biodiversity'] += 15;
        }

        $scores['society'] = max(0, min(100, $scores['society']));
        $scores['biodiversity'] = max(0, min(100, $scores['biodiversity']));
        $scores['ghg'] = max(0, min(100, $scores['ghg']));

        return $scores;
    }

    /**
     * @param array<string, mixed> $evidence
     */
    private function computeConfidence(array $evidence): float
    {
        $confidence = 0.35;

        $company = is_array($evidence['company'] ?? null) ? $evidence['company'] : [];
        if (true === ($company['checked'] ?? false)) {
            $confidence += 0.05;
        }
        if (true === ($company['found'] ?? false)) {
            $confidence += 0.10;
        }
        if (true === ($company['active'] ?? false)) {
            $confidence += 0.10;
        }
        if (true === ($company['isEss'] ?? false)) {
            $confidence += 0.05;
        }
        if (true === ($company['isMissionCompany'] ?? false)) {
            $confidence += 0.05;
        }
        if (true === ($company['hasGesReport'] ?? false)) {
            $confidence += 0.05;
        }

        $location = is_array($evidence['location'] ?? null) ? $evidence['location'] : [];
        if (true === ($location['checked'] ?? false)) {
            $confidence += 0.05;
        }
        if (true === ($location['validated'] ?? false)) {
            $confidence += 0.05;
        }

        return max(0.20, min(1.00, round($confidence, 2)));
    }
}
