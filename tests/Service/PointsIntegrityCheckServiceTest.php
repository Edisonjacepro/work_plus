<?php

namespace App\Tests\Service;

use App\Repository\PointsIntegrityRepository;
use App\Service\PointsIntegrityCheckService;
use PHPUnit\Framework\TestCase;

class PointsIntegrityCheckServiceTest extends TestCase
{
    public function testRunReturnsHealthyReportWhenNoIssue(): void
    {
        $repository = $this->createMock(PointsIntegrityRepository::class);
        $repository->expects(self::once())->method('countApprovedClaimsWithoutCredit')->willReturn(0);
        $repository->expects(self::once())->method('countLedgerCreditsWithoutClaim')->willReturn(0);
        $repository->expects(self::once())->method('countApprovedClaimsPointsMismatch')->willReturn(0);
        $repository->expects(self::once())->method('countDuplicateClaimApprovalCredits')->willReturn(0);
        $repository->expects(self::never())->method('findApprovedClaimsWithoutCredit');
        $repository->expects(self::never())->method('findLedgerCreditsWithoutClaim');
        $repository->expects(self::never())->method('findApprovedClaimsPointsMismatch');
        $repository->expects(self::never())->method('findDuplicateClaimApprovalCredits');

        $service = new PointsIntegrityCheckService($repository);
        $report = $service->run(15);

        self::assertFalse($report['hasIssues']);
        self::assertSame(0, $report['totalIssues']);
        self::assertSame(0, $report['counts'][PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT]);
        self::assertSame([], $report['samples'][PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT]);
    }

    public function testRunReturnsIssueSamplesWhenIssuesExist(): void
    {
        $repository = $this->createMock(PointsIntegrityRepository::class);
        $repository->expects(self::once())->method('countApprovedClaimsWithoutCredit')->willReturn(1);
        $repository->expects(self::once())->method('countLedgerCreditsWithoutClaim')->willReturn(2);
        $repository->expects(self::once())->method('countApprovedClaimsPointsMismatch')->willReturn(0);
        $repository->expects(self::once())->method('countDuplicateClaimApprovalCredits')->willReturn(1);

        $repository->expects(self::once())
            ->method('findApprovedClaimsWithoutCredit')
            ->with(3)
            ->willReturn([['claimId' => 10, 'companyId' => 4, 'approvedPoints' => 12]]);
        $repository->expects(self::once())
            ->method('findLedgerCreditsWithoutClaim')
            ->with(3)
            ->willReturn([['ledgerEntryId' => 9, 'referenceId' => 200, 'companyId' => 4, 'points' => 12]]);
        $repository->expects(self::never())->method('findApprovedClaimsPointsMismatch');
        $repository->expects(self::once())
            ->method('findDuplicateClaimApprovalCredits')
            ->with(3)
            ->willReturn([['claimId' => 10, 'creditsCount' => 2, 'creditedPoints' => 24]]);

        $service = new PointsIntegrityCheckService($repository);
        $report = $service->run(3);

        self::assertTrue($report['hasIssues']);
        self::assertSame(4, $report['totalIssues']);
        self::assertCount(1, $report['samples'][PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT]);
        self::assertCount(1, $report['samples'][PointsIntegrityCheckService::ISSUE_LEDGER_CREDITS_WITHOUT_CLAIM]);
        self::assertCount(1, $report['samples'][PointsIntegrityCheckService::ISSUE_DUPLICATE_CLAIM_APPROVAL_CREDITS]);
    }
}
