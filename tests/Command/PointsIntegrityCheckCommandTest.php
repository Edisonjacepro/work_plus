<?php

namespace App\Tests\Command;

use App\Command\PointsIntegrityCheckCommand;
use App\Service\PointsIntegrityAlertService;
use App\Service\PointsIntegrityCheckService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

class PointsIntegrityCheckCommandTest extends TestCase
{
    public function testExecuteReturnsSuccessWhenNoIssue(): void
    {
        $service = $this->createMock(PointsIntegrityCheckService::class);
        $alertService = $this->createMock(PointsIntegrityAlertService::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);

        $lockFactory->expects(self::once())
            ->method('createLock')
            ->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $service->expects(self::once())
            ->method('run')
            ->with(20)
            ->willReturn($this->buildReport(false, 0));
        $alertService->expects(self::never())->method('notifyIntegrityFailure');
        $alertService->expects(self::never())->method('notifyExecutionFailure');

        $tester = new CommandTester(new PointsIntegrityCheckCommand($service, $alertService, $lockFactory, 900));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Total issues: 0', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureWhenIssueExists(): void
    {
        $service = $this->createMock(PointsIntegrityCheckService::class);
        $alertService = $this->createMock(PointsIntegrityAlertService::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);

        $lockFactory->expects(self::once())
            ->method('createLock')
            ->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $service->expects(self::once())
            ->method('run')
            ->with(10)
            ->willReturn($this->buildReport(true, 2, [
                PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT => 2,
            ], [
                PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT => [
                    ['claimId' => 11, 'companyId' => 5, 'approvedPoints' => 15],
                ],
            ]));
        $alertService->expects(self::once())->method('notifyIntegrityFailure');
        $alertService->expects(self::never())->method('notifyExecutionFailure');

        $tester = new CommandTester(new PointsIntegrityCheckCommand($service, $alertService, $lockFactory, 900));
        $exitCode = $tester->execute(['--sample-limit' => 10]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString(PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT, $tester->getDisplay());
    }

    public function testExecuteOutputsJsonReport(): void
    {
        $service = $this->createMock(PointsIntegrityCheckService::class);
        $alertService = $this->createMock(PointsIntegrityAlertService::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);

        $lockFactory->expects(self::once())
            ->method('createLock')
            ->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $service->expects(self::once())
            ->method('run')
            ->with(5)
            ->willReturn($this->buildReport(false, 0));
        $alertService->expects(self::never())->method('notifyIntegrityFailure');
        $alertService->expects(self::never())->method('notifyExecutionFailure');

        $tester = new CommandTester(new PointsIntegrityCheckCommand($service, $alertService, $lockFactory, 900));
        $tester->execute([
            '--sample-limit' => 5,
            '--json' => true,
        ]);

        $decoded = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('checkedAt', $decoded);
        self::assertSame(0, $decoded['totalIssues']);
    }

    public function testExecuteSkipsWhenLockAlreadyAcquired(): void
    {
        $service = $this->createMock(PointsIntegrityCheckService::class);
        $alertService = $this->createMock(PointsIntegrityAlertService::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);

        $lockFactory->expects(self::once())
            ->method('createLock')
            ->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(false);
        $lock->expects(self::never())->method('release');
        $service->expects(self::never())->method('run');
        $alertService->expects(self::never())->method('notifyIntegrityFailure');
        $alertService->expects(self::never())->method('notifyExecutionFailure');

        $tester = new CommandTester(new PointsIntegrityCheckCommand($service, $alertService, $lockFactory, 900));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already running', strtolower($tester->getDisplay()));
    }

    public function testExecuteNotifiesExecutionFailureOnCrash(): void
    {
        $service = $this->createMock(PointsIntegrityCheckService::class);
        $alertService = $this->createMock(PointsIntegrityAlertService::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);

        $lockFactory->expects(self::once())
            ->method('createLock')
            ->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');
        $service->expects(self::once())->method('run')->willThrowException(new \RuntimeException('DB timeout'));
        $alertService->expects(self::never())->method('notifyIntegrityFailure');
        $alertService->expects(self::once())->method('notifyExecutionFailure');

        $tester = new CommandTester(new PointsIntegrityCheckCommand($service, $alertService, $lockFactory, 900));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('crashed unexpectedly', strtolower($tester->getDisplay()));
    }

    /**
     * @param array<string, int> $counts
     * @param array<string, list<array<string, mixed>>> $samples
     * @return array{
     *     checkedAt: \DateTimeImmutable,
     *     hasIssues: bool,
     *     totalIssues: int,
     *     counts: array<string, int>,
     *     samples: array<string, list<array<string, mixed>>>
     * }
     */
    private function buildReport(bool $hasIssues, int $totalIssues, array $counts = [], array $samples = []): array
    {
        $defaultCounts = [
            PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT => 0,
            PointsIntegrityCheckService::ISSUE_LEDGER_CREDITS_WITHOUT_CLAIM => 0,
            PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_POINTS_MISMATCH => 0,
            PointsIntegrityCheckService::ISSUE_DUPLICATE_CLAIM_APPROVAL_CREDITS => 0,
        ];
        $defaultSamples = [
            PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_WITHOUT_CREDIT => [],
            PointsIntegrityCheckService::ISSUE_LEDGER_CREDITS_WITHOUT_CLAIM => [],
            PointsIntegrityCheckService::ISSUE_APPROVED_CLAIMS_POINTS_MISMATCH => [],
            PointsIntegrityCheckService::ISSUE_DUPLICATE_CLAIM_APPROVAL_CREDITS => [],
        ];

        return [
            'checkedAt' => new \DateTimeImmutable('2026-02-24T12:00:00+00:00'),
            'hasIssues' => $hasIssues,
            'totalIssues' => $totalIssues,
            'counts' => array_replace($defaultCounts, $counts),
            'samples' => array_replace($defaultSamples, $samples),
        ];
    }
}
