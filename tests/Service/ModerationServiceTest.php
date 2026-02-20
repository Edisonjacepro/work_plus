<?php

namespace App\Tests\Service;

use App\Dto\EligibilityResult;
use App\Entity\ModerationReview;
use App\Entity\Offer;
use App\Service\ImpactEligibilityService;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ModerationServiceTest extends TestCase
{
    public function testModerateForPublicationApprovesEligibleOffer(): void
    {
        $impactEligibilityService = $this->createMock(ImpactEligibilityService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new ModerationService($impactEligibilityService, $entityManager);

        $offer = (new Offer())
            ->setTitle('Offre impact')
            ->setDescription(str_repeat('Impact positif concret. ', 8))
            ->setImpactCategories(['societe']);

        $impactEligibilityService->expects(self::once())
            ->method('evaluate')
            ->with($offer)
            ->willReturn(new EligibilityResult(
                eligible: true,
                reasonCode: 'ELIGIBLE',
                reasonText: 'Eligible.',
                score: 72,
                ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
                metadata: ['totalImpactScore' => 66],
            ));

        $persisted = [];
        $entityManager->expects(self::exactly(4))
            ->method('persist')
            ->willReturnCallback(static function (mixed $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $result = $service->moderateForPublication($offer);

        self::assertTrue($result->eligible);
        self::assertSame(Offer::STATUS_PUBLISHED, $offer->getStatus());
        self::assertSame(Offer::MODERATION_STATUS_APPROVED, $offer->getModerationStatus());
        self::assertSame(72, $offer->getModerationScore());
        self::assertCount(4, $persisted);
        self::assertInstanceOf(ModerationReview::class, $persisted[1]);
        self::assertInstanceOf(ModerationReview::class, $persisted[2]);
    }

    public function testModerateForPublicationRejectsNonEligibleOffer(): void
    {
        $impactEligibilityService = $this->createMock(ImpactEligibilityService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new ModerationService($impactEligibilityService, $entityManager);

        $offer = (new Offer())
            ->setTitle('Offre impact')
            ->setDescription(str_repeat('Texte court mais present. ', 8))
            ->setImpactCategories(['societe']);

        $impactEligibilityService->expects(self::once())
            ->method('evaluate')
            ->with($offer)
            ->willReturn(new EligibilityResult(
                eligible: false,
                reasonCode: 'LOW_IMPACT_SCORE',
                reasonText: 'Score insuffisant.',
                score: 24,
                ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
                metadata: ['totalImpactScore' => 18],
            ));

        $entityManager->expects(self::exactly(4))->method('persist');

        $result = $service->moderateForPublication($offer);

        self::assertFalse($result->eligible);
        self::assertSame(Offer::STATUS_DRAFT, $offer->getStatus());
        self::assertSame(Offer::MODERATION_STATUS_REJECTED, $offer->getModerationStatus());
        self::assertFalse($offer->isVisible());
        self::assertNull($offer->getPublishedAt());
    }
}
