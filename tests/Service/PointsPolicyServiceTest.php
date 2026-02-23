<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsLedgerEntryRepository;
use App\Service\PointsPolicyService;
use PHPUnit\Framework\TestCase;

class PointsPolicyServiceTest extends TestCase
{
    public function testEvaluateCompanyCreditReturnsNullWhenWithinCaps(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $service = new PointsPolicyService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 10);

        $repository->expects(self::exactly(2))
            ->method('sumCompanyCreditPointsSince')
            ->with(10, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(20, 300);
        $repository->expects(self::once())
            ->method('countCompanyCreditEntriesSince')
            ->with(10, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(1);
        $repository->expects(self::once())
            ->method('countCompanyCreditEntriesByReferenceSince')
            ->with(10, PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(4);

        $decision = $service->evaluateCompanyCredit(
            $company,
            25,
            PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
            new \DateTimeImmutable('2026-02-23 10:00:00'),
        );

        self::assertNull($decision);
    }

    public function testEvaluateCompanyCreditRejectsOnDailyPointsCapForClaims(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $service = new PointsPolicyService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 11);

        $repository->expects(self::once())
            ->method('sumCompanyCreditPointsSince')
            ->with(11, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(170);
        $repository->expects(self::never())->method('countCompanyCreditEntriesSince');

        $decision = $service->evaluateCompanyCredit(
            $company,
            25,
            PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
            new \DateTimeImmutable('2026-02-23 10:00:00'),
        );

        self::assertIsArray($decision);
        self::assertSame(PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP, $decision['reasonCode']);
    }

    public function testEvaluateCompanyCreditRejectsOnMonthlyClaimQuota(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $service = new PointsPolicyService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 12);

        $repository->expects(self::exactly(2))
            ->method('sumCompanyCreditPointsSince')
            ->with(12, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(20, 300);
        $repository->expects(self::once())
            ->method('countCompanyCreditEntriesSince')
            ->with(12, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(1);
        $repository->expects(self::once())
            ->method('countCompanyCreditEntriesByReferenceSince')
            ->with(12, PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(PointsPolicyService::COMPANY_MONTHLY_POINTS_CLAIM_CAP);

        $decision = $service->evaluateCompanyCredit(
            $company,
            20,
            PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
            new \DateTimeImmutable('2026-02-23 10:00:00'),
        );

        self::assertIsArray($decision);
        self::assertSame(PointsClaim::REASON_CODE_FREEMIUM_MONTHLY_CLAIMS_QUOTA, $decision['reasonCode']);
    }

    public function testEvaluateCompanyCreditRejectsOnOfferFreemiumQuota(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $service = new PointsPolicyService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 13);

        $repository->expects(self::exactly(2))
            ->method('sumCompanyCreditPointsSince')
            ->with(13, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(20, 200);
        $repository->expects(self::once())
            ->method('countCompanyCreditEntriesSince')
            ->with(13, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(2);
        $repository->expects(self::once())
            ->method('countCompanyCreditEntriesByReferenceSince')
            ->with(13, PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(PointsPolicyService::COMPANY_MONTHLY_OFFER_PUBLICATION_CAP);

        $decision = $service->evaluateCompanyCredit(
            $company,
            30,
            PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION,
            new \DateTimeImmutable('2026-02-23 10:00:00'),
        );

        self::assertIsArray($decision);
        self::assertSame('FREEMIUM_MONTHLY_OFFER_PUBLICATION_CAP', $decision['reasonCode']);
    }

    public function testEvaluateUserCreditRejectsOnDailyPointsCap(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $service = new PointsPolicyService($repository);
        $user = (new User())->setEmail('candidate@example.com')->setPassword('secret');
        $this->setEntityId($user, 20);

        $repository->expects(self::once())
            ->method('sumUserCreditPointsSince')
            ->with(20, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(35);
        $repository->expects(self::never())->method('countUserCreditEntriesSince');

        $decision = $service->evaluateUserCredit(
            $user,
            10,
            PointsLedgerEntry::REFERENCE_APPLICATION_HIRED,
            new \DateTimeImmutable('2026-02-23 10:00:00'),
        );

        self::assertIsArray($decision);
        self::assertSame('USER_DAILY_POINTS_CAP', $decision['reasonCode']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
