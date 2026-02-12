<?php

namespace App\Tests\Service;

use App\Entity\ModerationReview;
use App\Entity\Offer;
use App\Entity\User;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

class ModerationServiceTest extends TestCase
{
    public function testSubmitMovesOfferToSubmitted(): void
    {
        $offer = new Offer();
        $user = new User();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $service = new ModerationService($entityManager);
        $service->submit($offer, $user);

        self::assertSame(Offer::STATUS_SUBMITTED, $offer->getStatus());
    }

    #[AllowMockObjectsWithoutExpectations]
    public function testRejectRequiresReason(): void
    {
        $offer = new Offer();
        $offer->setStatus(Offer::STATUS_SUBMITTED);

        $user = new User();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new ModerationService($entityManager);

        $this->expectException(\DomainException::class);
        $service->reject($offer, $user, '   ');
    }

    public function testApproveCreatesReviewAndUpdatesStatus(): void
    {
        $offer = new Offer();
        $offer->setStatus(Offer::STATUS_SUBMITTED);

        $user = new User();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(ModerationReview::class));
        $entityManager->expects(self::once())->method('flush');

        $service = new ModerationService($entityManager);
        $review = $service->approve($offer, $user, null);

        self::assertSame(Offer::STATUS_APPROVED, $offer->getStatus());
        self::assertSame(ModerationReview::DECISION_APPROVED, $review->getDecision());
    }
}
