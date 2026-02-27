<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\PointsClaimReviewEvent;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsClaimRepository;
use App\Repository\PointsLedgerEntryRepository;
use App\Service\PointsPolicyAuditService;
use App\Service\PointsPolicyRiskService;
use App\Service\PointsPolicyService;
use App\Service\PointsClaimService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PointsClaimServiceTest extends TestCase
{
    public function testSubmitReturnsExistingClaimWhenIdempotencyKeyAlreadyExists(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

        $existing = (new PointsClaim())->setIdempotencyKey('claim-key-1');

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-1')
            ->willReturn($existing);

        $entityManager->expects(self::never())->method('persist');
        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');
        $policyRiskService->expects(self::never())->method('getCompanyRiskSummary');

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
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

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
        $policyService->expects(self::once())
            ->method('evaluateCompanyCredit')
            ->with($company, 25, PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL)
            ->willReturn(null);
        $policyRiskService->expects(self::once())
            ->method('getCompanyRiskSummary')
            ->with($company)
            ->willReturn($this->cooldownInactiveSummary());
        $policyAuditService->expects(self::once())
            ->method('recordCompanyDecision')
            ->with(
                $company,
                25,
                PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                null,
                null,
                [
                    'claimType' => PointsClaim::CLAIM_TYPE_TRAINING,
                    'claimIdempotencyKey' => 'claim-key-2',
                ],
            );

        $ledgerRepository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('points_claim_approval_claim-key-2')
            ->willReturn(false);

        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeStatement')
            ->with(
                self::stringContains('ON CONFLICT DO NOTHING'),
                self::callback(static function (array $params): bool {
                    return 101 === $params['referenceId']
                        && 'points_claim_approval_claim-key-2' === $params['idempotencyKey']
                        && 25 === $params['points'];
                }),
                self::callback(static fn (mixed $types): bool => is_array($types)),
            )
            ->willReturn(1);

        $persisted = [];
        $entityManager->expects(self::exactly(3))
            ->method('persist')
            ->willReturnCallback(function (mixed $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(function () use (&$persisted): void {
                foreach ($persisted as $entity) {
                    if (!$entity instanceof PointsClaim || null !== $entity->getId()) {
                        continue;
                    }

                    $reflection = new \ReflectionProperty($entity::class, 'id');
                    $reflection->setAccessible(true);
                    $reflection->setValue($entity, 101);
                }
            });
        $entityManager->expects(self::once())
            ->method('getConnection')
            ->willReturn($connection);

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
        self::assertCount(3, $persisted);
        self::assertInstanceOf(PointsClaimReviewEvent::class, $persisted[1]);
        self::assertInstanceOf(PointsClaimReviewEvent::class, $persisted[2]);
    }

    public function testSubmitAutoApproveDoesNotInsertLedgerWhenEntryAlreadyExists(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 27);

        $claimRepository->method('findOneByIdempotencyKey')->with('claim-key-2b')->willReturn(null);
        $claimRepository->method('hasEvidenceHashForCompany')->willReturn(false);
        $policyRiskService->method('getCompanyRiskSummary')->with($company)->willReturn($this->cooldownInactiveSummary());
        $policyService->method('evaluateCompanyCredit')->willReturn(null);
        $policyAuditService->expects(self::once())->method('recordCompanyDecision');

        $ledgerRepository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('points_claim_approval_claim-key-2b')
            ->willReturn(true);

        $persisted = [];
        $entityManager->expects(self::exactly(3))
            ->method('persist')
            ->willReturnCallback(function (mixed $entity) use (&$persisted): void {
                $persisted[] = $entity;
            });
        $entityManager->expects(self::once())
            ->method('flush')
            ->willReturnCallback(function () use (&$persisted): void {
                foreach ($persisted as $entity) {
                    if (!$entity instanceof PointsClaim || null !== $entity->getId()) {
                        continue;
                    }

                    $reflection = new \ReflectionProperty($entity::class, 'id');
                    $reflection->setAccessible(true);
                    $reflection->setValue($entity, 102);
                }
            });
        $entityManager->expects(self::never())->method('getConnection');

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_TRAINING,
            [
                ['valid' => true, 'fileHash' => 'h1'],
                ['valid' => true, 'fileHash' => 'h2'],
                ['valid' => true, 'fileHash' => 'h3'],
                ['valid' => true, 'fileHash' => 'h4'],
            ],
            'claim-key-2b',
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
        self::assertSame(25, $claim->getApprovedPoints());
    }

    public function testSubmitRejectsWhenAntiFraudCapIsReached(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 17);

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-antifraud')
            ->willReturn(null);

        $claimRepository->expects(self::exactly(4))
            ->method('hasEvidenceHashForCompany')
            ->with(17, self::callback(static fn (mixed $value): bool => is_string($value)))
            ->willReturn(false);

        $policyService->expects(self::once())
            ->method('evaluateCompanyCredit')
            ->with($company, 25, PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL)
            ->willReturn([
                'reasonCode' => PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP,
                'reasonText' => 'Cap journalier de points entreprise depasse.',
                'metadata' => ['cap' => 180, 'currentPoints' => 170, 'points' => 25],
            ]);
        $policyRiskService->expects(self::once())
            ->method('getCompanyRiskSummary')
            ->with($company)
            ->willReturn($this->cooldownInactiveSummary());
        $policyAuditService->expects(self::once())
            ->method('recordCompanyDecision')
            ->with(
                $company,
                25,
                PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                null,
                [
                    'reasonCode' => PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP,
                    'reasonText' => 'Cap journalier de points entreprise depasse.',
                    'metadata' => ['cap' => 180, 'currentPoints' => 170, 'points' => 25],
                ],
                [
                    'claimType' => PointsClaim::CLAIM_TYPE_TRAINING,
                    'claimIdempotencyKey' => 'claim-key-antifraud',
                ],
            );

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $entityManager->expects(self::exactly(3))->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_TRAINING,
            [
                ['valid' => true, 'fileHash' => 'a1'],
                ['valid' => true, 'fileHash' => 'a2'],
                ['valid' => true, 'fileHash' => 'a3'],
                ['valid' => true, 'fileHash' => 'a4'],
            ],
            'claim-key-antifraud',
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

        self::assertSame(PointsClaim::STATUS_REJECTED, $claim->getStatus());
        self::assertSame(PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP, $claim->getDecisionReasonCode());
        self::assertNull($claim->getApprovedPoints());
    }

    public function testSubmitRejectsWhenCooldownIsActive(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 19);

        $claimRepository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->with('claim-key-cooldown')
            ->willReturn(null);
        $claimRepository->expects(self::never())->method('hasEvidenceHashForCompany');

        $cooldownUntil = new \DateTimeImmutable('2026-02-24 14:00:00');
        $policyRiskService->expects(self::once())
            ->method('getCompanyRiskSummary')
            ->with($company)
            ->willReturn([
                'blocked24h' => 7,
                'blocked7d' => 11,
                'cooldownActive' => true,
                'cooldownUntil' => $cooldownUntil,
                'threshold24h' => 5,
                'durationMinutes' => 120,
            ]);
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::once())
            ->method('recordCompanyDecision')
            ->with(
                $company,
                25,
                PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                null,
                [
                    'reasonCode' => 'COMPANY_COOLDOWN_ACTIVE',
                    'reasonText' => 'Pause de sécurité activée après plusieurs refus automatiques récents.',
                    'metadata' => [
                        'ruleVersion' => PointsPolicyService::RULE_VERSION,
                        'blocked24h' => 7,
                        'blocked7d' => 11,
                        'threshold24h' => 5,
                        'cooldownUntil' => $cooldownUntil->format(DATE_ATOM),
                    ],
                ],
                [
                    'claimType' => PointsClaim::CLAIM_TYPE_TRAINING,
                    'claimIdempotencyKey' => 'claim-key-cooldown',
                ],
            );

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $entityManager->expects(self::exactly(3))->method('persist');
        $entityManager->expects(self::never())->method('flush');

        $claim = $service->submit(
            $company,
            PointsClaim::CLAIM_TYPE_TRAINING,
            [
                ['valid' => true, 'fileHash' => 'c1'],
                ['valid' => true, 'fileHash' => 'c2'],
                ['valid' => true, 'fileHash' => 'c3'],
                ['valid' => true, 'fileHash' => 'c4'],
            ],
            'claim-key-cooldown',
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

        self::assertSame(PointsClaim::STATUS_REJECTED, $claim->getStatus());
        self::assertSame(PointsClaim::REASON_CODE_COOLDOWN_ACTIVE, $claim->getDecisionReasonCode());
        self::assertNull($claim->getApprovedPoints());
    }

    public function testSubmitRejectsWhenEvidenceScoreIsBelowAutomaticThreshold(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

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
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');
        $policyRiskService->expects(self::once())
            ->method('getCompanyRiskSummary')
            ->with($company)
            ->willReturn($this->cooldownInactiveSummary());
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
        self::assertStringContainsString('score 45/100', (string) $claim->getDecisionReason());
        self::assertStringContainsString('seuil 70', (string) $claim->getDecisionReason());
        self::assertTrue(is_array($claim->getExternalChecks()));
        $coherence = $claim->getExternalChecks()['coherence'] ?? null;
        self::assertTrue(is_array($coherence));
        self::assertFalse((bool) ($coherence['isCoherent'] ?? true));
        self::assertContains('profile_complete', $coherence['failedRequired'] ?? []);
        self::assertNull($claim->getApprovedPoints());
    }

    public function testSubmitRejectsWhenEvidenceDateTooOld(): void
    {
        $claimRepository = $this->createMock(PointsClaimRepository::class);
        $ledgerRepository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 9);

        $claimRepository->method('findOneByIdempotencyKey')->willReturn(null);
        $claimRepository->method('hasEvidenceHashForCompany')->willReturn(false);
        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');
        $policyRiskService->expects(self::once())
            ->method('getCompanyRiskSummary')
            ->with($company)
            ->willReturn($this->cooldownInactiveSummary());
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
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 11);

        $claimRepository->method('findOneByIdempotencyKey')->willReturn(null);
        $claimRepository->expects(self::once())
            ->method('hasEvidenceHashForCompany')
            ->with(11, 'dup-hash')
            ->willReturn(true);

        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');
        $policyRiskService->expects(self::once())
            ->method('getCompanyRiskSummary')
            ->with($company)
            ->willReturn($this->cooldownInactiveSummary());
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
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

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
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');
        $policyRiskService->expects(self::never())->method('getCompanyRiskSummary');
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
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $policyRiskService = $this->createMock(PointsPolicyRiskService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsClaimService($claimRepository, $ledgerRepository, $policyService, $policyAuditService, $policyRiskService, $entityManager);

        $claim = (new PointsClaim())
            ->setStatus(PointsClaim::STATUS_SUBMITTED)
            ->setRequestedPoints(10)
            ->setIdempotencyKey('claim-key-5')
            ->setCompany((new Company())->setName('Impact Co'));
        $reviewer = (new User())->setEmail('reviewer@example.com')->setPassword('secret');

        $entityManager->expects(self::never())->method('persist');
        $ledgerRepository->expects(self::never())->method('existsByIdempotencyKey');
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');
        $policyRiskService->expects(self::never())->method('getCompanyRiskSummary');

        $this->expectException(\LogicException::class);
        $service->reject($claim, $reviewer, PointsClaim::REASON_CODE_REJECTED_BY_REVIEWER, 'comment');
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }

    /**
     * @return array{blocked24h: int, blocked7d: int, cooldownActive: bool, cooldownUntil: ?\DateTimeImmutable, threshold24h: int, durationMinutes: int}
     */
    private function cooldownInactiveSummary(): array
    {
        return [
            'blocked24h' => 0,
            'blocked7d' => 0,
            'cooldownActive' => false,
            'cooldownUntil' => null,
            'threshold24h' => 5,
            'durationMinutes' => 120,
        ];
    }
}








