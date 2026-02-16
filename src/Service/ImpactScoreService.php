<?php

namespace App\Service;

use App\Entity\ImpactScore;
use App\Entity\Offer;
use Doctrine\ORM\EntityManagerInterface;

class ImpactScoreService
{
    public function __construct(
        private readonly ImpactScoringService $impactScoringService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function computeAndStore(Offer $offer): ImpactScore
    {
        $result = $this->impactScoringService->score($offer);

        $impactScore = (new ImpactScore())
            ->setOffer($offer)
            ->setSocietyScore($result->societyScore)
            ->setBiodiversityScore($result->biodiversityScore)
            ->setGhgScore($result->ghgScore)
            ->setTotalScore($result->totalScore)
            ->setConfidence($result->confidence)
            ->setRuleVersion($result->ruleVersion)
            ->setEvidence($result->evidence)
            ->setIsAutomated(true);

        $this->entityManager->persist($impactScore);

        return $impactScore;
    }
}
