<?php

namespace App\Tests\Service;

use App\Dto\ImpactScoreResult;
use App\Entity\Offer;
use App\Service\ImpactEligibilityService;
use App\Service\ImpactScoringService;
use PHPUnit\Framework\TestCase;

class ImpactEligibilityServiceTest extends TestCase
{
    public function testRejectsOfferContainingForbiddenKeyword(): void
    {
        $impactScoringService = $this->createMock(ImpactScoringService::class);
        $impactScoringService->expects(self::never())->method('score');

        $service = new ImpactEligibilityService($impactScoringService);

        $offer = (new Offer())
            ->setTitle('Mission locale')
            ->setDescription(str_repeat('Description utile ', 10) . 'sans violence')
            ->setImpactCategories(['societe']);

        $result = $service->evaluate($offer);

        self::assertFalse($result->eligible);
        self::assertSame(ImpactEligibilityService::REASON_CODE_FORBIDDEN_ACTIVITY, $result->reasonCode);
    }

    public function testApprovesOfferWhenAutomaticScoreIsSufficient(): void
    {
        $impactScoringService = $this->createMock(ImpactScoringService::class);
        $impactScoringService->expects(self::once())
            ->method('score')
            ->willReturn(new ImpactScoreResult(
                societyScore: 70,
                biodiversityScore: 45,
                ghgScore: 60,
                totalScore: 60,
                confidence: 0.8,
                ruleVersion: 'impact_v1_test',
                evidence: [],
            ));

        $service = new ImpactEligibilityService($impactScoringService);

        $offer = (new Offer())
            ->setTitle('Animation biodiversite')
            ->setDescription(str_repeat('Projet a impact concret et mesurable. ', 5))
            ->setImpactCategories(['societe', 'biodiversite']);

        $result = $service->evaluate($offer);

        self::assertTrue($result->eligible);
        self::assertSame(ImpactEligibilityService::REASON_CODE_ELIGIBLE, $result->reasonCode);
        self::assertGreaterThanOrEqual(40, $result->score);
    }

    public function testRejectsOfferWhenAutomaticScoreIsTooLow(): void
    {
        $impactScoringService = $this->createMock(ImpactScoringService::class);
        $impactScoringService->expects(self::once())
            ->method('score')
            ->willReturn(new ImpactScoreResult(
                societyScore: 20,
                biodiversityScore: 10,
                ghgScore: 15,
                totalScore: 20,
                confidence: 0.35,
                ruleVersion: 'impact_v1_test',
                evidence: [],
            ));

        $service = new ImpactEligibilityService($impactScoringService);

        $offer = (new Offer())
            ->setTitle('Projet local')
            ->setDescription(str_repeat('Action de terrain avec suivi. ', 6))
            ->setImpactCategories(['societe']);

        $result = $service->evaluate($offer);

        self::assertFalse($result->eligible);
        self::assertSame(ImpactEligibilityService::REASON_CODE_LOW_IMPACT_SCORE, $result->reasonCode);
    }
}
