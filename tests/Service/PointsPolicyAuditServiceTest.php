<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\PointsPolicyDecision;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Service\PointsPolicyAuditService;
use App\Service\PointsPolicyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PointsPolicyAuditServiceTest extends TestCase
{
    public function testRecordCompanyDecisionCreatesAllowDecisionWhenPolicyAllows(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsPolicyAuditService($entityManager);

        $company = (new Company())->setName('Impact Co');
        $captured = null;
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (mixed $entity) use (&$captured): bool {
                $captured = $entity;

                return $entity instanceof PointsPolicyDecision;
            }));

        $service->recordCompanyDecision(
            company: $company,
            points: 25,
            referenceType: PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
            referenceId: 44,
            policyDecision: null,
            metadata: ['claimType' => 'TRAINING'],
        );

        self::assertInstanceOf(PointsPolicyDecision::class, $captured);
        self::assertSame(PointsPolicyDecision::STATUS_ALLOW, $captured->getDecisionStatus());
        self::assertSame(PointsPolicyDecision::REASON_CODE_ALLOWED, $captured->getReasonCode());
        self::assertSame(PointsPolicyService::RULE_VERSION, $captured->getRuleVersion());
        self::assertSame($company, $captured->getCompany());
        self::assertSame(44, $captured->getReferenceId());
        self::assertSame(['claimType' => 'TRAINING'], $captured->getMetadata());
    }

    public function testRecordUserDecisionCreatesBlockDecisionAndMergesMetadata(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsPolicyAuditService($entityManager);

        $user = (new User())->setEmail('candidate@example.com')->setPassword('secret');
        $captured = null;
        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(static function (mixed $entity) use (&$captured): bool {
                $captured = $entity;

                return $entity instanceof PointsPolicyDecision;
            }));

        $service->recordUserDecision(
            user: $user,
            points: 13,
            referenceType: PointsLedgerEntry::REFERENCE_APPLICATION_HIRED,
            referenceId: 77,
            policyDecision: [
                'reasonCode' => 'USER_DAILY_POINTS_CAP',
                'reasonText' => 'Cap depasse.',
                'metadata' => [
                    'ruleVersion' => 'points_policy_v1_2026_02',
                    'currentPoints' => 35,
                ],
            ],
            metadata: [
                'offerId' => 9,
            ],
        );

        self::assertInstanceOf(PointsPolicyDecision::class, $captured);
        self::assertSame(PointsPolicyDecision::STATUS_BLOCK, $captured->getDecisionStatus());
        self::assertSame('USER_DAILY_POINTS_CAP', $captured->getReasonCode());
        self::assertSame('Cap depasse.', $captured->getReasonText());
        self::assertSame('points_policy_v1_2026_02', $captured->getRuleVersion());
        self::assertSame($user, $captured->getUser());
        self::assertSame(77, $captured->getReferenceId());
        self::assertSame([
            'ruleVersion' => 'points_policy_v1_2026_02',
            'currentPoints' => 35,
            'offerId' => 9,
        ], $captured->getMetadata());
    }
}
