<?php

namespace App\Tests\Service;

use App\Dto\ImpactScoreResult;
use App\Entity\ImpactScore;
use App\Entity\Offer;
use App\Repository\ImpactScoreRepository;
use App\Service\ImpactScoringService;
use App\Service\OfferImpactScoreResolver;
use PHPUnit\Framework\TestCase;

class OfferImpactScoreResolverTest extends TestCase
{
    public function testReturnsStoredScoreWhenAvailable(): void
    {
        $repository = $this->createMock(ImpactScoreRepository::class);
        $scoringService = $this->createMock(ImpactScoringService::class);
        $resolver = new OfferImpactScoreResolver($repository, $scoringService);

        $offer = (new Offer())
            ->setTitle('Offer')
            ->setDescription('Description')
            ->setImpactCategories(['climat']);
        $this->setEntityId($offer, 99);

        $storedScore = (new ImpactScore())
            ->setOffer($offer)
            ->setTotalScore(60)
            ->setSocietyScore(40)
            ->setBiodiversityScore(30)
            ->setGhgScore(70)
            ->setConfidence(0.8);

        $repository->expects(self::once())
            ->method('findLatestForOffer')
            ->with(99)
            ->willReturn($storedScore);

        $scoringService->expects(self::never())->method('score');

        $resolved = $resolver->resolve($offer);

        self::assertFalse($resolved['isPreview']);
        self::assertSame($storedScore, $resolved['impactScore']);
    }

    public function testReturnsPreviewScoreWhenNoStoredScore(): void
    {
        $repository = $this->createMock(ImpactScoreRepository::class);
        $scoringService = $this->createMock(ImpactScoringService::class);
        $resolver = new OfferImpactScoreResolver($repository, $scoringService);

        $offer = (new Offer())
            ->setTitle('Offer')
            ->setDescription('Description')
            ->setImpactCategories(['ges']);
        $this->setEntityId($offer, 10);

        $preview = new ImpactScoreResult(
            societyScore: 35,
            biodiversityScore: 20,
            ghgScore: 70,
            totalScore: 42,
            confidence: 0.75,
            ruleVersion: 'v1',
            evidence: [],
        );

        $repository->expects(self::once())
            ->method('findLatestForOffer')
            ->with(10)
            ->willReturn(null);

        $scoringService->expects(self::once())
            ->method('score')
            ->with($offer)
            ->willReturn($preview);

        $resolved = $resolver->resolve($offer);

        self::assertTrue($resolved['isPreview']);
        self::assertSame($preview, $resolved['impactScore']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
