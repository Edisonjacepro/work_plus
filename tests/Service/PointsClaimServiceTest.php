<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsClaimRepository;
use App\Repository\PointsLedgerEntryRepository;
use App\Service\PointsClaimService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PointsClaimServiceTest extends TestCase
{
    public function testSubmitReturnsExistingClaimWhenIdempotencyKeyAlreadyExists(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $existing = (new PointsClaim())->setIdempotencyKey('claim-key-1');

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-1')
            ->willReturn($existing);

        $entityManager->expects(self::never())->method('persist');
        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');

        $result = $service->submit(
            new Company(),
            PointsClaim::CLAIM_TYPE_OTHER,
            [['valid' => true]],
            'claim-key-1',
        );

        self::assertSame($existing, $result);
    }

    public function testSubmitAutoApprovesWhenEvidenceScoreIsHigh(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $company = (new Company())->setName('Impact Co');

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-2')
            ->willReturn(null);

        $ledgerRepository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('points_claim_approval_claim-key-2')
            ->willReturn(false);

        $persisted = [];
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (mixed $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_TRAINING,
            [
                ['valid' => true],
                ['valid' => true],
                ['valid' => true],
                ['valid' => true],
            ],
            'claim-key-2',
            null,
            [
                'coherenceOk' => true,
                'checks' => [
                    'companyFound' => true,
                    'companyActive' => true,
                    'sectorMatch' => true,
                    'cityMatch' => true,
                ],
            ],
        );

        self::assertSame(PointsClaim::STATUS_APPROVED, $claim->getStatus());
        self::assertSame(100, $claim->getEvidenceScore());
        self::assertSame(25, $claim->getApprovedPoints());
        self::assertSame(25, $claim->getRequestedPoints());
        self::assertCount(2, $persisted);
        self::assertInstanceOf(PointsLedgerEntry::class, $persisted[1]);
    }

    public function testSubmitSetsInReviewForMediumEvidenceScore(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $company = (new Company())->setName('Impact Co');

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-3')
            ->willReturn(null);

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $entityManager->expects(self::once())->method('persist');

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_VOLUNTEERING,
            [
                ['valid' => true],
                ['valid' => false],
                ['valid' => false],
                ['valid' => false],
            ],
            'claim-key-3',
            null,
            null,
        );

        self::assertSame(PointsClaim::STATUS_IN_REVIEW, $claim->getStatus());
        self::assertSame(45, $claim->getEvidenceScore());
        self::assertSame(11, $claim->getRequestedPoints());
        self::assertNull($claim->getApprovedPoints());
    }

    public function testApproveCreatesLedgerEntryForClaimInReview(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $reviewer = (new User())->setEmail('reviewer@example.com')->setPassword('secret');
        $claim = (new PointsClaim())
            ->setCompany($company)
            ->setClaimType(PointsClaim::CLAIM_TYPE_CERTIFICATION)
            ->setStatus(PointsClaim::STATUS_IN_REVIEW)
            ->setRequestedPoints(30)
            ->setEvidenceScore(62)
            ->setIdempotencyKey('claim-key-4');

        $ledgerRepository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('points_claim_approval_claim-key-4')
            ->willReturn(false);

        $persisted = [];
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->willReturnCallback(static function (mixed $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $entry = $service->approve($claim, $reviewer, 22, 'Manual approval after review');

        self::assertInstanceOf(PointsLedgerEntry::class, $entry);
        self::assertSame(PointsClaim::STATUS_APPROVED, $claim->getStatus());
        self::assertSame(22, $claim->getApprovedPoints());
        self::assertSame($reviewer, $claim->getReviewedBy());
        self::assertCount(2, $persisted);
    }

    public function testRejectRequiresReason(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $claim = (new PointsClaim())
            ->setStatus(PointsClaim::STATUS_IN_REVIEW)
            ->setRequestedPoints(10)
            ->setIdempotencyKey('claim-key-5')
            ->setCompany((new Company())->setName('Impact Co'));
        $reviewer = (new User())->setEmail('reviewer@example.com')->setPassword('secret');

        $entityManager->expects(self::never())->method('persist');

        $this->expectException(\InvalidArgumentException::class);
        $service->reject($claim, $reviewer, '   ');
    }
}
