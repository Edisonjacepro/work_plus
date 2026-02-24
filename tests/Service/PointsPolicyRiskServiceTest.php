<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Repository\PointsPolicyDecisionRepository;
use App\Service\PointsPolicyRiskService;
use PHPUnit\Framework\TestCase;

class PointsPolicyRiskServiceTest extends TestCase
{
    public function testGetCompanyRiskSummaryReturnsCooldownActiveWhenThresholdReachedAndWindowOpen(): void
    {
        $repository = $this->createMock(PointsPolicyDecisionRepository::class);
        $service = new PointsPolicyRiskService($repository, [
            'threshold_24h_blocks' => 5,
            'duration_minutes' => 120,
        ]);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 7);

        $now = new \DateTimeImmutable('2026-02-24 12:00:00');
        $repository->expects(self::exactly(2))
            ->method('countBlockedForCompanySince')
            ->with(7, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(7, 10);
        $repository->expects(self::once())
            ->method('findLatestBlockedAtForCompany')
            ->with(7)
            ->willReturn(new \DateTimeImmutable('2026-02-24 11:30:00'));

        $summary = $service->getCompanyRiskSummary($company, $now);

        self::assertTrue($summary['cooldownActive']);
        self::assertSame(7, $summary['blocked24h']);
        self::assertSame(10, $summary['blocked7d']);
        self::assertSame(5, $summary['threshold24h']);
        self::assertSame(120, $summary['durationMinutes']);
        self::assertInstanceOf(\DateTimeImmutable::class, $summary['cooldownUntil']);
    }

    public function testGetCompanyRiskSummaryReturnsCooldownInactiveWhenBelowThreshold(): void
    {
        $repository = $this->createMock(PointsPolicyDecisionRepository::class);
        $service = new PointsPolicyRiskService($repository, [
            'threshold_24h_blocks' => 5,
            'duration_minutes' => 120,
        ]);

        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 8);

        $now = new \DateTimeImmutable('2026-02-24 12:00:00');
        $repository->expects(self::exactly(2))
            ->method('countBlockedForCompanySince')
            ->with(8, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturnOnConsecutiveCalls(3, 6);
        $repository->expects(self::once())
            ->method('findLatestBlockedAtForCompany')
            ->with(8)
            ->willReturn(new \DateTimeImmutable('2026-02-24 11:30:00'));

        $summary = $service->getCompanyRiskSummary($company, $now);

        self::assertFalse($summary['cooldownActive']);
        self::assertNull($summary['cooldownUntil']);
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
