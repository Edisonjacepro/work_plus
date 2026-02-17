<?php

namespace App\Tests\Service;

use App\Entity\Application;
use App\Entity\ImpactScore;
use App\Entity\Offer;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\ImpactScoreRepository;
use App\Repository\PointsLedgerEntryRepository;
use App\Service\CandidatePointsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class CandidatePointsServiceTest extends TestCase
{
    public function testAwardApplicationHiredPointsCreatesCreditWithImpactBonus(): void
    {
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $impactScoreRepository = $this->createMock(ImpactScoreRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new CandidatePointsService($ledgerRepository, $impactScoreRepository, $entityManager);

        $candidate = (new User())->setEmail('candidate@example.com')->setAccountType(User::ACCOUNT_TYPE_PERSON);
        $offer = (new Offer())->setTitle('Offer')->setDescription('Description');
        $application = (new Application())
            ->setCandidate($candidate)
            ->setOffer($offer)
            ->setStatus(Application::STATUS_HIRED);

        $this->setEntityId($candidate, 3);
        $this->setEntityId($offer, 9);
        $this->setEntityId($application, 14);

        $impactScore = (new ImpactScore())
            ->setOffer($offer)
            ->setTotalScore(80)
            ->setRuleVersion('v1_test');

        $ledgerRepository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('application_hired_candidate_14')
            ->willReturn(false);

        $impactScoreRepository->expects(self::once())
            ->method('findLatestForOffer')
            ->with(9)
            ->willReturn($impactScore);

        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entry) use ($candidate): bool {
                if (!$entry instanceof PointsLedgerEntry) {
                    return false;
                }

                return 13 === $entry->getPoints()
                    && PointsLedgerEntry::TYPE_CREDIT === $entry->getEntryType()
                    && PointsLedgerEntry::REFERENCE_APPLICATION_HIRED === $entry->getReferenceType()
                    && 'application_hired_candidate_14' === $entry->getIdempotencyKey()
                    && $candidate === $entry->getUser();
            }));

        $entry = $service->awardApplicationHiredPoints($application);

        self::assertInstanceOf(PointsLedgerEntry::class, $entry);
        self::assertSame(13, $entry->getPoints());
    }

    public function testAwardApplicationHiredPointsReturnsNullForAnonymousCandidate(): void
    {
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $impactScoreRepository = $this->createMock(ImpactScoreRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new CandidatePointsService($ledgerRepository, $impactScoreRepository, $entityManager);

        $offer = (new Offer())->setTitle('Offer')->setDescription('Description');
        $application = (new Application())
            ->setOffer($offer)
            ->setStatus(Application::STATUS_HIRED);
        $this->setEntityId($offer, 9);
        $this->setEntityId($application, 14);

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $impactScoreRepository->expects(self::never())->method('findLatestForOffer');
        $entityManager->expects(self::never())->method('persist');

        self::assertNull($service->awardApplicationHiredPoints($application));
    }

    public function testAwardApplicationHiredPointsReturnsNullWhenApplicationNotHired(): void
    {
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $impactScoreRepository = $this->createMock(ImpactScoreRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new CandidatePointsService($ledgerRepository, $impactScoreRepository, $entityManager);

        $candidate = (new User())->setEmail('candidate@example.com')->setAccountType(User::ACCOUNT_TYPE_PERSON);
        $offer = (new Offer())->setTitle('Offer')->setDescription('Description');
        $application = (new Application())
            ->setCandidate($candidate)
            ->setOffer($offer)
            ->setStatus(Application::STATUS_SUBMITTED);
        $this->setEntityId($candidate, 3);
        $this->setEntityId($offer, 9);
        $this->setEntityId($application, 14);

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $impactScoreRepository->expects(self::never())->method('findLatestForOffer');
        $entityManager->expects(self::never())->method('persist');

        self::assertNull($service->awardApplicationHiredPoints($application));
    }

    public function testGetCandidateSummaryReturnsBalanceLevelAndHistory(): void
    {
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $impactScoreRepository = $this->createMock(ImpactScoreRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new CandidatePointsService($ledgerRepository, $impactScoreRepository, $entityManager);

        $candidate = (new User())->setEmail('candidate@example.com')->setAccountType(User::ACCOUNT_TYPE_PERSON);
        $this->setEntityId($candidate, 55);

        $historyEntry = (new PointsLedgerEntry())
            ->setEntryType(PointsLedgerEntry::TYPE_CREDIT)
            ->setReferenceType(PointsLedgerEntry::REFERENCE_APPLICATION_HIRED)
            ->setReason('Candidate points for hired application')
            ->setPoints(10);

        $ledgerRepository->expects(self::once())
            ->method('getUserBalance')
            ->with(55)
            ->willReturn(320);

        $ledgerRepository->expects(self::once())
            ->method('findLatestForUser')
            ->with(55, 20)
            ->willReturn([$historyEntry]);

        $summary = $service->getCandidateSummary($candidate);

        self::assertSame(320, $summary['balance']);
        self::assertSame('Gold', $summary['level']);
        self::assertCount(1, $summary['history']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
