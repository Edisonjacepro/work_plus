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

    public const COMPANY_DAILY_POINTS_CAP = 180;
    public const COMPANY_DAILY_CREDITS_CAP = 6;
    public const COMPANY_MONTHLY_POINTS_CAP = 1500;
    public const COMPANY_MONTHLY_OFFER_PUBLICATION_CAP = 60;
    public const COMPANY_MONTHLY_POINTS_CLAIM_CAP = 25;

    public const USER_DAILY_POINTS_CAP = 40;
    public const USER_DAILY_CREDITS_CAP = 4;
    public const USER_MONTHLY_POINTS_CAP = 400;

    public function __construct(
        private readonly PointsLedgerEntryRepository $pointsLedgerEntryRepository,
    ) {
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

        $dailyPoints = $this->pointsLedgerEntryRepository->sumCompanyCreditPointsSince($companyId, $dayStart);
        if (($dailyPoints + $points) > self::COMPANY_DAILY_POINTS_CAP) {
            return $this->blockedDecision(
                reasonCode: $this->resolveCompanyDailyPointsReasonCode($referenceType),
                reasonText: 'Cap journalier de points entreprise depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'scope' => 'company',
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'points' => $points,
                    'currentPoints' => $dailyPoints,
                    'cap' => self::COMPANY_DAILY_POINTS_CAP,
                ],
            );
        }

        $dailyCredits = $this->pointsLedgerEntryRepository->countCompanyCreditEntriesSince($companyId, $dayStart);
        if ($dailyCredits >= self::COMPANY_DAILY_CREDITS_CAP) {
            return $this->blockedDecision(
                reasonCode: $this->resolveCompanyDailyCreditsReasonCode($referenceType),
                reasonText: 'Cap journalier de credits entreprise depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'scope' => 'company',
                    'period' => 'day',
                    'periodStart' => $dayStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'currentCredits' => $dailyCredits,
                    'cap' => self::COMPANY_DAILY_CREDITS_CAP,
                ],
            );
        }

        $monthlyPoints = $this->pointsLedgerEntryRepository->sumCompanyCreditPointsSince($companyId, $monthStart);
        if (($monthlyPoints + $points) > self::COMPANY_MONTHLY_POINTS_CAP) {
            return $this->blockedDecision(
                reasonCode: $this->resolveCompanyMonthlyPointsReasonCode($referenceType),
                reasonText: 'Cap mensuel de points entreprise depasse.',
                metadata: [
                    'ruleVersion' => self::RULE_VERSION,
                    'scope' => 'company',
                    'period' => 'month',
                    'periodStart' => $monthStart->format(DATE_ATOM),
                    'referenceType' => $referenceType,
                    'points' => $points,
                    'currentPoints' => $monthlyPoints,
                    'cap' => self::COMPANY_MONTHLY_POINTS_CAP,
                ],
            );
        }

        if (PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION === $referenceType) {
            $offerCreditsMonth = $this->pointsLedgerEntryRepository->countCompanyCreditEntriesByReferenceSince(
                $companyId,
                PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION,
                $monthStart,
            );

            if ($offerCreditsMonth >= self::COMPANY_MONTHLY_OFFER_PUBLICATION_CAP) {
                return $this->blockedDecision(
                    reasonCode: 'FREEMIUM_MONTHLY_OFFER_PUBLICATION_CAP',
                    reasonText: 'Quota freemium mensuel des offres publiees depasse.',
                    metadata: [
                        'ruleVersion' => self::RULE_VERSION,
                        'scope' => 'company',
                        'period' => 'month',
                        'periodStart' => $monthStart->format(DATE_ATOM),
                        'referenceType' => $referenceType,
                        'currentCredits' => $offerCreditsMonth,
                        'cap' => self::COMPANY_MONTHLY_OFFER_PUBLICATION_CAP,
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

            if ($claimCreditsMonth >= self::COMPANY_MONTHLY_POINTS_CLAIM_CAP) {
                return $this->blockedDecision(
                    reasonCode: PointsClaim::REASON_CODE_FREEMIUM_MONTHLY_CLAIMS_QUOTA,
                    reasonText: 'Quota freemium mensuel des demandes de points depasse.',
                    metadata: [
                        'ruleVersion' => self::RULE_VERSION,
                        'scope' => 'company',
                        'period' => 'month',
                        'periodStart' => $monthStart->format(DATE_ATOM),
                        'referenceType' => $referenceType,
                        'currentCredits' => $claimCreditsMonth,
                        'cap' => self::COMPANY_MONTHLY_POINTS_CLAIM_CAP,
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

        $dailyPoints = $this->pointsLedgerEntryRepository->sumUserCreditPointsSince($userId, $dayStart);
        if (($dailyPoints + $points) > self::USER_DAILY_POINTS_CAP) {
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
                    'cap' => self::USER_DAILY_POINTS_CAP,
                ],
            );
        }

        $dailyCredits = $this->pointsLedgerEntryRepository->countUserCreditEntriesSince($userId, $dayStart);
        if ($dailyCredits >= self::USER_DAILY_CREDITS_CAP) {
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
                    'cap' => self::USER_DAILY_CREDITS_CAP,
                ],
            );
        }

        $monthlyPoints = $this->pointsLedgerEntryRepository->sumUserCreditPointsSince($userId, $monthStart);
        if (($monthlyPoints + $points) > self::USER_MONTHLY_POINTS_CAP) {
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
                    'cap' => self::USER_MONTHLY_POINTS_CAP,
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
}
