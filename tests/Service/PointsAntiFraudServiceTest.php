<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Repository\PointsClaimRepository;
use App\Service\PointsAntiFraudService;
use PHPUnit\Framework\TestCase;

class PointsAntiFraudServiceTest extends TestCase
{
    public function testEvaluateApprovalReturnsNullWhenWithinCaps(): void
    {
        $repository = $this->createMock(PointsClaimRepository::class);
        $service = new PointsAntiFraudService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 10);

        $repository->expects(self::exactly(2))
            ->method('sumApprovedPointsSince')
            ->with(10, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(20, 200);
        $repository->expects(self::once())
            ->method('countApprovedClaimsSince')
            ->with(10, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(1);

        $decision = $service->evaluateApproval($company, 25, new \DateTimeImmutable('2026-02-23 10:00:00'));

        self::assertNull($decision);
    }

    public function testEvaluateApprovalRejectsWhenDailyPointsCapWouldBeExceeded(): void
    {
        $repository = $this->createMock(PointsClaimRepository::class);
        $service = new PointsAntiFraudService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 11);

        $repository->expects(self::once())
            ->method('sumApprovedPointsSince')
            ->with(11, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(50);
        $repository->expects(self::never())->method('countApprovedClaimsSince');

        $decision = $service->evaluateApproval($company, 25, new \DateTimeImmutable('2026-02-23 10:00:00'));

        self::assertIsArray($decision);
        self::assertSame(PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP, $decision['reasonCode']);
    }

    public function testEvaluateApprovalRejectsWhenDailyClaimsCapIsReached(): void
    {
        $repository = $this->createMock(PointsClaimRepository::class);
        $service = new PointsAntiFraudService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 12);

        $repository->expects(self::once())
            ->method('sumApprovedPointsSince')
            ->with(12, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(20);
        $repository->expects(self::once())
            ->method('countApprovedClaimsSince')
            ->with(12, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(PointsAntiFraudService::DAILY_APPROVED_CLAIMS_CAP);

        $decision = $service->evaluateApproval($company, 15, new \DateTimeImmutable('2026-02-23 10:00:00'));

        self::assertIsArray($decision);
        self::assertSame(PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_CLAIMS_CAP, $decision['reasonCode']);
    }

    public function testEvaluateApprovalRejectsWhenMonthlyPointsCapWouldBeExceeded(): void
    {
        $repository = $this->createMock(PointsClaimRepository::class);
        $service = new PointsAntiFraudService($repository);
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 13);

        $repository->expects(self::exactly(2))
            ->method('sumApprovedPointsSince')
            ->with(13, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(20, 590);
        $repository->expects(self::once())
            ->method('countApprovedClaimsSince')
            ->with(13, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(1);

        $decision = $service->evaluateApproval($company, 20, new \DateTimeImmutable('2026-02-23 10:00:00'));

        self::assertIsArray($decision);
        self::assertSame(PointsClaim::REASON_CODE_ANTI_FRAUD_MONTHLY_POINTS_CAP, $decision['reasonCode']);
    }

    public function testEvaluateApprovalThrowsWithoutCompanyId(): void
    {
        $repository = $this->createMock(PointsClaimRepository::class);
        $service = new PointsAntiFraudService($repository);

        $this->expectException(\InvalidArgumentException::class);
        $service->evaluateApproval(new Company(), 15, new \DateTimeImmutable('2026-02-23 10:00:00'));
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
