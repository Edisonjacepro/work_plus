<?php

namespace App\Service;

use App\Repository\PointsIntegrityRepository;

class PointsIntegrityCheckService
{
    public const ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT = 'approved_claims_without_credit';
    public const ISSUE_LEDGER_CREDITS_WITHOUT_CLAIM = 'ledger_credits_without_claim';
    public const ISSUE_APPROVED_CLAIMS_POINTS_MISMATCH = 'approved_claims_points_mismatch';
    public const ISSUE_DUPLICATE_CLAIM_APPROVAL_CREDITS = 'duplicate_claim_approval_credits';

    public function __construct(private readonly PointsIntegrityRepository $pointsIntegrityRepository)
    {
    }

    /**
     * @return array{
     *     checkedAt: \DateTimeImmutable,
     *     hasIssues: bool,
     *     totalIssues: int,
     *     counts: array{
     *         approved_claims_without_credit: int,
     *         ledger_credits_without_claim: int,
     *         approved_claims_points_mismatch: int,
     *         duplicate_claim_approval_credits: int
     *     },
     *     samples: array{
     *         approved_claims_without_credit: list<array{claimId: int, companyId: int, approvedPoints: int|null}>,
     *         ledger_credits_without_claim: list<array{ledgerEntryId: int, referenceId: int, companyId: int|null, points: int}>,
     *         approved_claims_points_mismatch: list<array{claimId: int, companyId: int, approvedPoints: int|null, creditedPoints: int}>,
     *         duplicate_claim_approval_credits: list<array{claimId: int, creditsCount: int, creditedPoints: int}>
     *     }
     * }
     */
    public function run(int $sampleLimit = 20): array
    {
        $sampleLimit = max(1, $sampleLimit);

        $counts = [
            self::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT => $this->pointsIntegrityRepository->countApprovedClaimsWithoutCredit(),
            self::ISSUE_LEDGER_CREDITS_WITHOUT_CLAIM => $this->pointsIntegrityRepository->countLedgerCreditsWithoutClaim(),
            self::ISSUE_APPROVED_CLAIMS_POINTS_MISMATCH => $this->pointsIntegrityRepository->countApprovedClaimsPointsMismatch(),
            self::ISSUE_DUPLICATE_CLAIM_APPROVAL_CREDITS => $this->pointsIntegrityRepository->countDuplicateClaimApprovalCredits(),
        ];

        $samples = [
            self::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT => $counts[self::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT] > 0
                ? $this->pointsIntegrityRepository->findApprovedClaimsWithoutCredit($sampleLimit)
                : [],
            self::ISSUE_LEDGER_CREDITS_WITHOUT_CLAIM => $counts[self::ISSUE_LEDGER_CREDITS_WITHOUT_CLAIM] > 0
                ? $this->pointsIntegrityRepository->findLedgerCreditsWithoutClaim($sampleLimit)
                : [],
            self::ISSUE_APPROVED_CLAIMS_POINTS_MISMATCH => $counts[self::ISSUE_APPROVED_CLAIMS_POINTS_MISMATCH] > 0
                ? $this->pointsIntegrityRepository->findApprovedClaimsPointsMismatch($sampleLimit)
                : [],
            self::ISSUE_DUPLICATE_CLAIM_APPROVAL_CREDITS => $counts[self::ISSUE_DUPLICATE_CLAIM_APPROVAL_CREDITS] > 0
                ? $this->pointsIntegrityRepository->findDuplicateClaimApprovalCredits($sampleLimit)
                : [],
        ];

        $totalIssues = array_sum($counts);

        return [
            'checkedAt' => new \DateTimeImmutable(),
            'hasIssues' => $totalIssues > 0,
            'totalIssues' => $totalIssues,
            'counts' => $counts,
            'samples' => $samples,
        ];
    }
}
