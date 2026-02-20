<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\PointsClaimReviewEvent;
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
        $this->setEntityId($company, 7);

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-2')
            ->willReturn(null);

        $claimRepository->expects(self::exactly(4))
            ->method('hasEvidenceHashForCompany')
            ->with(7, self::callback(static fn (mixed $value): bool => is_string($value)))
            ->willReturn(false);

        $ledgerRepository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('points_claim_approval_claim-key-2')
            ->willReturn(false);

        $persisted = [];
        $entityManager->expects(self::exactly(4))
            ->method('persist')
            ->willReturnCallback(static function (mixed $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_TRAINING,
            [
                ['valid' => true, 'fileHash' => 'h1'],
                ['valid' => true, 'fileHash' => 'h2'],
                ['valid' => true, 'fileHash' => 'h3'],
                ['valid' => true, 'fileHash' => 'h4'],
            ],
            'claim-key-2',
            null,
            new \DateTimeImmutable('today'),
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
        self::assertSame(PointsClaim::REASON_CODE_AUTO_APPROVED_SCORE, $claim->getDecisionReasonCode());
        self::assertCount(4, $persisted);
        self::assertInstanceOf(PointsClaimReviewEvent::class, $persisted[1]);
        self::assertInstanceOf(PointsClaimReviewEvent::class, $persisted[2]);
        self::assertInstanceOf(PointsLedgerEntry::class, $persisted[3]);
    }

    public function testSubmitRejectsWhenEvidenceScoreIsBelowAutomaticThreshold(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 8);

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-3')
            ->willReturn(null);

        $claimRepository->expects(self::exactly(4))
            ->method('hasEvidenceHashForCompany')
            ->with(8, self::callback(static fn (mixed $value): bool => is_string($value)))
            ->willReturn(false);

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $entityManager->expects(self::exactly(3))->method('persist');

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_VOLUNTEERING,
            [
                ['valid' => true, 'fileHash' => 'm1'],
                ['valid' => false, 'fileHash' => 'm2'],
                ['valid' => false, 'fileHash' => 'm3'],
                ['valid' => false, 'fileHash' => 'm4'],
            ],
            'claim-key-3',
            null,
            new \DateTimeImmutable('today'),
            null,
        );

        self::assertSame(PointsClaim::STATUS_REJECTED, $claim->getStatus());
        self::assertSame(45, $claim->getEvidenceScore());
        self::assertSame(11, $claim->getRequestedPoints());
        self::assertSame(PointsClaim::REASON_CODE_INSUFFICIENT_EVIDENCE_SCORE, $claim->getDecisionReasonCode());
        self::assertNull($claim->getApprovedPoints());
    }

    public function testSubmitRejectsWhenEvidenceDateTooOld(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 9);

        $claimRepository->method('findOneByIdempotencyKey')->willReturn(null);
        $claimRepository->method('hasEvidenceHashForCompany')->willReturn(false);
        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $entityManager->expects(self::exactly(3))->method('persist');

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_CERTIFICATION,
            [
                ['valid' => true, 'fileHash' => 'o1'],
            ],
            'claim-key-old',
            null,
            (new \DateTimeImmutable('today'))->modify('-3 years'),
            null,
        );

        self::assertSame(PointsClaim::STATUS_REJECTED, $claim->getStatus());
        self::assertSame(PointsClaim::REASON_CODE_EVIDENCE_TOO_OLD, $claim->getDecisionReasonCode());
    }

    public function testSubmitRejectsWhenDuplicateEvidenceHashDetected(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 11);

        $claimRepository->method('findOneByIdempotencyKey')->willReturn(null);
        $claimRepository->expects(self::once())
            ->method('hasEvidenceHashForCompany')
            ->with(11, 'dup-hash')
            ->willReturn(true);

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $entityManager->expects(self::exactly(3))->method('persist');

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_CERTIFICATION,
            [
                ['valid' => true, 'fileHash' => 'dup-hash'],
            ],
            'claim-key-dup',
            null,
            new \DateTimeImmutable('today'),
            null,
        );

        self::assertSame(PointsClaim::STATUS_REJECTED, $claim->getStatus());
        self::assertSame(PointsClaim::REASON_CODE_DUPLICATE_EVIDENCE_FILE, $claim->getDecisionReasonCode());
    }

    public function testApproveThrowsWhenManualReviewIsDisabled(): void
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
            ->setStatus(PointsClaim::STATUS_SUBMITTED)
            ->setRequestedPoints(30)
            ->setEvidenceScore(62)
            ->setIdempotencyKey('claim-key-4');

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $entityManager->expects(self::never())->method('persist');

        $this->expectException(\LogicException::class);

        $service->approve(
            claim: $claim,
            reviewer: $reviewer,
            reasonCode: PointsClaim::REASON_CODE_APPROVED_BY_REVIEWER,
            approvedPoints: 22,
            reasonNote: 'Manual approval after review',
        );
    }

    public function testRejectThrowsWhenManualReviewIsDisabled(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $entityManager);

        $claim = (new PointsClaim())
            ->setStatus(PointsClaim::STATUS_SUBMITTED)
            ->setRequestedPoints(10)
            ->setIdempotencyKey('claim-key-5')
            ->setCompany((new Company())->setName('Impact Co'));
        $reviewer = (new User())->setEmail('reviewer@example.com')->setPassword('secret');

        $entityManager->expects(self::never())->method('persist');
        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');

        $this->expectException(\LogicException::class);
        $service->reject($claim, $reviewer, PointsClaim::REASON_CODE_REJECTED_BY_REVIEWER, 'comment');
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
