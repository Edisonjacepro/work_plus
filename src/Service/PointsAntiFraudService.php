<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Repository\PointsClaimRepository;

class PointsAntiFraudService
{
    public const RULE_VERSION = 'points_antifraud_v1_2026_02';
    public const DAILY_APPROVED_POINTS_CAP = 60;
    public const DAILY_APPROVED_CLAIMS_CAP = 3;
    public const MONTHLY_APPROVED_POINTS_CAP = 600;

    public function __construct(
        private readonly PointsClaimRepository $pointsClaimRepository,
    ) {
    }

    /**
     * @return array{reasonCode: string, reasonText: string, metadata: array<string, mixed>}|null
     */
    public function evaluateApproval(Company $company, int $suggestedPoints, ?\DateTimeImmutable $now = null): ?array
    {
        if ($suggestedPoints <= 0) {
            return null;
        }

        $companyId = $company->getId();
        if (null === $companyId) {
            throw new \InvalidArgumentException('Company id is required for anti-fraud checks.');
        }

        $referenceTime = $now ?? new \DateTimeImmutable('now');
        $dayStart = $referenceTime->setTime(0, 0);
        $monthStart = $referenceTime->modify('first day of this month')->setTime(0, 0);

        $approvedPointsToday = $this->pointsClaimRepository->sumApprovedPointsSince($companyId, $dayStart);
        if (($approvedPointsToday + $suggestedPoints) > self::DAILY_APPROVED_POINTS_CAP) {
            return [
                'reasonCode' => PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP,
                'reasonText' => 'Cap journalier de points depasse.',
                'metadata' => [
                    'ruleVersion' => self::RULE_VERSION,
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'approvedPoints' => $approvedPointsToday,
                    'suggestedPoints' => $suggestedPoints,
                    'cap' => self::DAILY_APPROVED_POINTS_CAP,
                ],
            ];
        }

        $approvedClaimsToday = $this->pointsClaimRepository->countApprovedClaimsSince($companyId, $dayStart);
        if ($approvedClaimsToday >= self::DAILY_APPROVED_CLAIMS_CAP) {
            return [
                'reasonCode' => PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_CLAIMS_CAP,
                'reasonText' => 'Cap journalier de validations depasse.',
                'metadata' => [
                    'ruleVersion' => self::RULE_VERSION,
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'approvedClaims' => $approvedClaimsToday,
                    'suggestedPoints' => $suggestedPoints,
                    'cap' => self::DAILY_APPROVED_CLAIMS_CAP,
                ],
            ];
        }

        $approvedPointsMonth = $this->pointsClaimRepository->sumApprovedPointsSince($companyId, $monthStart);
        if (($approvedPointsMonth + $suggestedPoints) > self::MONTHLY_APPROVED_POINTS_CAP) {
            return [
                'reasonCode' => PointsClaim::REASON_CODE_ANTI_FRAUD_MONTHLY_POINTS_CAP,
                'reasonText' => 'Cap mensuel de points depasse.',
                'metadata' => [
                    'ruleVersion' => self::RULE_VERSION,
                    'period' => 'month',
                    'periodStart' => $monthStart->format(DATE_ATOM),
                    'approvedPoints' => $approvedPointsMonth,
                    'suggestedPoints' => $suggestedPoints,
                    'cap' => self::MONTHLY_APPROVED_POINTS_CAP,
                ],
            ];
        }

        return null;
    }
}
