<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsLedgerEntryRepository;

class PointsPolicyService
{
    public const RULE_VERSION = 'points_policy_v1_2026_02';

    private const COMPANY_LIMIT_DAILY_POINTS = 'company_daily_points_cap';
    private const COMPANY_LIMIT_DAILY_CREDITS = 'company_daily_credits_cap';
    private const COMPANY_LIMIT_MONTHLY_POINTS = 'company_monthly_points_cap';
    private const COMPANY_LIMIT_MONTHLY_OFFER_PUBLICATION = 'company_monthly_offer_publication_cap';
    private const COMPANY_LIMIT_MONTHLY_POINTS_CLAIM = 'company_monthly_points_claim_cap';
    private const USER_LIMIT_DAILY_POINTS = 'user_daily_points_cap';
    private const USER_LIMIT_DAILY_CREDITS = 'user_daily_credits_cap';
    private const USER_LIMIT_MONTHLY_POINTS = 'user_monthly_points_cap';

    /**
     * @var array<string, array<string, int>>
     */
    private array $companyPlanLimits;
    /**
     * @var array<string, int>
     */
    private array $userLimits;
    private string $defaultCompanyPlanCode;

    public function __construct(
        private readonly PointsLedgerEntryRepository $pointsLedgerEntryRepository,
        array $pointsPolicyCompanyPlanLimits,
        array $pointsPolicyUserLimits,
        string $pointsPolicyDefaultCompanyPlan,
    ) {
        $this->defaultCompanyPlanCode = strtoupper(trim($pointsPolicyDefaultCompanyPlan));
        if ('' === $this->defaultCompanyPlanCode) {
            throw new \InvalidArgumentException('Default company plan code cannot be empty.');
        }

        $this->companyPlanLimits = $this->normalizeCompanyPlanLimits(
            $pointsPolicyCompanyPlanLimits,
            $this->defaultCompanyPlanCode,
        );
        $this->userLimits = $this->normalizeUserLimits($pointsPolicyUserLimits);
    }

    /**
     * @return array{reasonCode: string, reasonText: string, metadata: array<string, mixed>}|null
     */
    public function evaluateCompanyCredit(
        Company $company,
        int $points,
        string $referenceType,
        ?\DateTimeImmutable $now = null,
    ): ?array {
        if ($points <= 0) {
            return null;
        }

        $companyId = $company->getId();
        if (null === $companyId) {
            throw new \InvalidArgumentException('Company id is required for points policy checks.');
        }

        $referenceTime = $now ?? new \DateTimeImmutable('now');
        $dayStart = $referenceTime->setTime(0, 0);
        $monthStart = $referenceTime->modify('first day of this month')->setTime(0, 0);
        $planCode = $this->resolveCompanyPlanCode($company, $referenceTime);
        $planLimits = $this->companyPlanLimits[$planCode];

        $dailyPoints = $this->pointsLedgerEntryRepository->sumCompanyCreditPointsSince($companyId, $dayStart);
        if (($dailyPoints + $points) > $planLimits[self::COMPANY_LIMIT_DAILY_POINTS]) {
            return $this->blockedDecision(
                reasonCode: $this->resolveCompanyDailyPointsReasonCode($referenceType),
                reasonText: 'Cap journalier de points entreprise depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'planCode' => $planCode,
                    'scope' => 'company',
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'points' => $points,
                    'currentPoints' => $dailyPoints,
                    'cap' => $planLimits[self::COMPANY_LIMIT_DAILY_POINTS],
                ],
            );
        }

        $dailyCredits = $this->pointsLedgerEntryRepository->countCompanyCreditEntriesSince($companyId, $dayStart);
        if ($dailyCredits >= $planLimits[self::COMPANY_LIMIT_DAILY_CREDITS]) {
            return $this->blockedDecision(
                reasonCode: $this->resolveCompanyDailyCreditsReasonCode($referenceType),
                reasonText: 'Cap journalier de credits entreprise depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'planCode' => $planCode,
                    'scope' => 'company',
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'currentCredits' => $dailyCredits,
                    'cap' => $planLimits[self::COMPANY_LIMIT_DAILY_CREDITS],
                ],
            );
        }

        $monthlyPoints = $this->pointsLedgerEntryRepository->sumCompanyCreditPointsSince($companyId, $monthStart);
        if (($monthlyPoints + $points) > $planLimits[self::COMPANY_LIMIT_MONTHLY_POINTS]) {
            return $this->blockedDecision(
                reasonCode: $this->resolveCompanyMonthlyPointsReasonCode($referenceType),
                reasonText: 'Cap mensuel de points entreprise depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'planCode' => $planCode,
                    'scope' => 'company',
                    'period' => 'month',
                    'periodStart' => $monthStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'points' => $points,
                    'currentPoints' => $monthlyPoints,
                    'cap' => $planLimits[self::COMPANY_LIMIT_MONTHLY_POINTS],
                ],
            );
        }

        if (PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION === $referenceType) {
            $offerCreditsMonth = $this->pointsLedgerEntryRepository->countCompanyCreditEntriesByReferenceSince(
                $companyId,
                PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION,
                $monthStart,
            );

            if ($offerCreditsMonth >= $planLimits[self::COMPANY_LIMIT_MONTHLY_OFFER_PUBLICATION]) {
                return $this->blockedDecision(
                    reasonCode: 'FREEMIUM_MONTHLY_OFFER_PUBLICATION_CAP',
                    reasonText: 'Quota freemium mensuel des offres publiees depasse.',
                    metadata: [
                        'ruleVersion' => self::RULE_VERSION,
                        'planCode' => $planCode,
                        'scope' => 'company',
                        'period' => 'month',
                        'periodStart' => $monthStart->format(DATE_ATOM),
                        'referenceType' => $referenceType,
                        'currentCredits' => $offerCreditsMonth,
                        'cap' => $planLimits[self::COMPANY_LIMIT_MONTHLY_OFFER_PUBLICATION],
                    ],
                );
            }
        }

        if (PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL === $referenceType) {
            $claimCreditsMonth = $this->pointsLedgerEntryRepository->countCompanyCreditEntriesByReferenceSince(
                $companyId,
                PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                $monthStart,
            );

            if ($claimCreditsMonth >= $planLimits[self::COMPANY_LIMIT_MONTHLY_POINTS_CLAIM]) {
                return $this->blockedDecision(
                    reasonCode: PointsClaim::REASON_CODE_FREEMIUM_MONTHLY_CLAIMS_QUOTA,
                    reasonText: 'Quota freemium mensuel des demandes de points depasse.',
                    metadata: [
                        'ruleVersion' => self::RULE_VERSION,
                        'planCode' => $planCode,
                        'scope' => 'company',
                        'period' => 'month',
                        'periodStart' => $monthStart->format(DATE_ATOM),
                        'referenceType' => $referenceType,
                        'currentCredits' => $claimCreditsMonth,
                        'cap' => $planLimits[self::COMPANY_LIMIT_MONTHLY_POINTS_CLAIM],
                    ],
                );
            }
        }

        return null;
    }

    /**
     * @return array{reasonCode: string, reasonText: string, metadata: array<string, mixed>}|null
     */
    public function evaluateUserCredit(
        User $user,
        int $points,
        string $referenceType,
        ?\DateTimeImmutable $now = null,
    ): ?array {
        if ($points <= 0) {
            return null;
        }

        $userId = $user->getId();
        if (null === $userId) {
            throw new \InvalidArgumentException('User id is required for points policy checks.');
        }

        $referenceTime = $now ?? new \DateTimeImmutable('now');
        $dayStart = $referenceTime->setTime(0, 0);
        $monthStart = $referenceTime->modify('first day of this month')->setTime(0, 0);
        $dailyPointsCap = $this->userLimits[self::USER_LIMIT_DAILY_POINTS];
        $dailyCreditsCap = $this->userLimits[self::USER_LIMIT_DAILY_CREDITS];
        $monthlyPointsCap = $this->userLimits[self::USER_LIMIT_MONTHLY_POINTS];

        $dailyPoints = $this->pointsLedgerEntryRepository->sumUserCreditPointsSince($userId, $dayStart);
        if (($dailyPoints + $points) > $dailyPointsCap) {
            return $this->blockedDecision(
                reasonCode: 'USER_DAILY_POINTS_CAP',
                reasonText: 'Cap journalier de points candidat depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'scope' => 'user',
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'points' => $points,
                    'currentPoints' => $dailyPoints,
                    'cap' => $dailyPointsCap,
                ],
            );
        }

        $dailyCredits = $this->pointsLedgerEntryRepository->countUserCreditEntriesSince($userId, $dayStart);
        if ($dailyCredits >= $dailyCreditsCap) {
            return $this->blockedDecision(
                reasonCode: 'USER_DAILY_CREDITS_CAP',
                reasonText: 'Cap journalier de credits candidat depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'scope' => 'user',
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'currentCredits' => $dailyCredits,
                    'cap' => $dailyCreditsCap,
                ],
            );
        }

        $monthlyPoints = $this->pointsLedgerEntryRepository->sumUserCreditPointsSince($userId, $monthStart);
        if (($monthlyPoints + $points) > $monthlyPointsCap) {
            return $this->blockedDecision(
                reasonCode: 'USER_MONTHLY_POINTS_CAP',
                reasonText: 'Cap mensuel de points candidat depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'scope' => 'user',
                    'period' => 'month',
                    'periodStart' => $monthStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'points' => $points,
                    'currentPoints' => $monthlyPoints,
                    'cap' => $monthlyPointsCap,
                ],
            );
        }

        return null;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{reasonCode: string, reasonText: string, metadata: array<string, mixed>}
     */
    private function blockedDecision(string $reasonCode, string $reasonText, array $metadata): array
    {
        return [
            'reasonCode' => $reasonCode,
            'reasonText' => $reasonText,
            'metadata' => $metadata,
        ];
    }

    private function resolveCompanyDailyPointsReasonCode(string $referenceType): string
    {
        if (PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL === $referenceType) {
            return PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP;
        }

        return 'COMPANY_DAILY_POINTS_CAP';
    }

    private function resolveCompanyDailyCreditsReasonCode(string $referenceType): string
    {
        if (PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL === $referenceType) {
            return PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_CLAIMS_CAP;
        }

        return 'COMPANY_DAILY_CREDITS_CAP';
    }

    private function resolveCompanyMonthlyPointsReasonCode(string $referenceType): string
    {
        if (PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL === $referenceType) {
            return PointsClaim::REASON_CODE_ANTI_FRAUD_MONTHLY_POINTS_CAP;
        }

        return 'COMPANY_MONTHLY_POINTS_CAP';
    }

    private function resolveCompanyPlanCode(Company $company, \DateTimeImmutable $referenceTime): string
    {
        $planCode = strtoupper(trim($company->getRecruiterPlanCode()));
        if ('' === $planCode || !isset($this->companyPlanLimits[$planCode])) {
            return $this->defaultCompanyPlanCode;
        }

        if (Company::RECRUITER_PLAN_STARTER !== $planCode && !$company->hasActivePaidPlan($referenceTime)) {
            return $this->defaultCompanyPlanCode;
        }

        return $planCode;
    }

    /**
     * @param array<string, mixed> $rawLimits
     * @return array<string, array<string, int>>
     */
    private function normalizeCompanyPlanLimits(array $rawLimits, string $defaultPlanCode): array
    {
        $requiredKeys = [
            self::COMPANY_LIMIT_DAILY_POINTS,
            self::COMPANY_LIMIT_DAILY_CREDITS,
            self::COMPANY_LIMIT_MONTHLY_POINTS,
            self::COMPANY_LIMIT_MONTHLY_OFFER_PUBLICATION,
            self::COMPANY_LIMIT_MONTHLY_POINTS_CLAIM,
        ];
        $normalized = [];

        foreach ($rawLimits as $planCode => $planLimits) {
            $normalizedPlanCode = strtoupper(trim((string) $planCode));
            if ('' === $normalizedPlanCode || !is_array($planLimits)) {
                continue;
            }

            $normalizedLimits = [];
            foreach ($requiredKeys as $key) {
                $value = $planLimits[$key] ?? null;
                if (!is_numeric($value) || (int) $value <= 0) {
                    throw new \InvalidArgumentException(sprintf('Invalid points policy limit "%s" for plan "%s".', $key, $normalizedPlanCode));
                }

                $normalizedLimits[$key] = (int) $value;
            }

            $normalized[$normalizedPlanCode] = $normalizedLimits;
        }

        if (!isset($normalized[$defaultPlanCode])) {
            throw new \InvalidArgumentException('Default company plan limits are missing from points policy config.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $rawUserLimits
     * @return array<string, int>
     */
    private function normalizeUserLimits(array $rawUserLimits): array
    {
        $requiredKeys = [
            self::USER_LIMIT_DAILY_POINTS,
            self::USER_LIMIT_DAILY_CREDITS,
            self::USER_LIMIT_MONTHLY_POINTS,
        ];
        $normalized = [];

        foreach ($requiredKeys as $key) {
            $value = $rawUserLimits[$key] ?? null;
            if (!is_numeric($value) || (int) $value <= 0) {
                throw new \InvalidArgumentException(sprintf('Invalid user points policy limit "%s".', $key));
            }

            $normalized[$key] = (int) $value;
        }

        return $normalized;
    }
}
