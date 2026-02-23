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
        $service = $this->createService($repository);
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
        $service = $this->createService($repository);
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
        $service = $this->createService($repository);
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
            ->willReturn(25);

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
        $service = $this->createService($repository);
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
            ->willReturn(60);

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
        $service = $this->createService($repository);
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

    public function testEvaluateCompanyCreditFallsBackToStarterWhenPaidPlanExpired(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $service = $this->createService($repository);
        $company = (new Company())
            ->setName('Impact Co')
            ->setRecruiterPlanCode(Company::RECRUITER_PLAN_GROWTH)
            ->setRecruiterPlanExpiresAt(new \DateTimeImmutable('2026-02-01 00:00:00'));
        $this->setEntityId($company, 14);

        $repository->expects(self::once())
            ->method('sumCompanyCreditPointsSince')
            ->with(14, self::isInstanceOf(\DateTimeImmutable::class))
            ->willReturn(170);
        $repository->expects(self::never())->method('countCompanyCreditEntriesSince');

        $decision = $service->evaluateCompanyCredit(
            $company,
            25,
            PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION,
            new \DateTimeImmutable('2026-02-23 10:00:00'),
        );

        self::assertIsArray($decision);
        self::assertSame('COMPANY_DAILY_POINTS_CAP', $decision['reasonCode']);
        self::assertSame('STARTER', $decision['metadata']['planCode'] ?? null);
    }

    /**
     * @param array<string, array<string, int>>|null $companyPlanLimits
     * @param array<string, int>|null $userLimits
     */
    private function createService(
        PointsLedgerEntryRepository $repository,
        ?array $companyPlanLimits = null,
        ?array $userLimits = null,
        string $defaultCompanyPlan = Company::RECRUITER_PLAN_STARTER,
    ): PointsPolicyService {
        return new PointsPolicyService(
            $repository,
            $companyPlanLimits ?? $this->defaultCompanyPlanLimits(),
            $userLimits ?? $this->defaultUserLimits(),
            $defaultCompanyPlan,
        );
    }

    /**
     * @return array<string, array<string, int>>
     */
    private function defaultCompanyPlanLimits(): array
    {
        return [
            Company::RECRUITER_PLAN_STARTER => [
                'company_daily_points_cap' => 180,
                'company_daily_credits_cap' => 6,
                'company_monthly_points_cap' => 1500,
                'company_monthly_offer_publication_cap' => 60,
                'company_monthly_points_claim_cap' => 25,
            ],
            Company::RECRUITER_PLAN_GROWTH => [
                'company_daily_points_cap' => 320,
                'company_daily_credits_cap' => 12,
                'company_monthly_points_cap' => 4000,
                'company_monthly_offer_publication_cap' => 240,
                'company_monthly_points_claim_cap' => 80,
            ],
            Company::RECRUITER_PLAN_SCALE => [
                'company_daily_points_cap' => 800,
                'company_daily_credits_cap' => 40,
                'company_monthly_points_cap' => 20000,
                'company_monthly_offer_publication_cap' => 2000,
                'company_monthly_points_claim_cap' => 600,
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function defaultUserLimits(): array
    {
        return [
            'user_daily_points_cap' => 40,
            'user_daily_credits_cap' => 4,
            'user_monthly_points_cap' => 400,
        ];
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
