<?php

namespace App\Tests\Command;

use App\Command\OpsHealthCheckCommand;
use App\Service\OpsHealthAlertService;
use App\Service\OpsHealthCheckService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class OpsHealthCheckCommandTest extends TestCase
{
    public function testExecuteReturnsSuccessWhenNoFailure(): void
    {
        $service = $this->createMock(OpsHealthCheckService::class);
        $alertService = $this->createMock(OpsHealthAlertService::class);
        $service->expects(self::once())
            ->method('run')
            ->willReturn($this->buildReport(false, 0, 1));
        $alertService->expects(self::never())->method('notifyHealthFailure');
        $alertService->expects(self::never())->method('notifyExecutionFailure');

        $tester = new CommandTester(new OpsHealthCheckCommand($service, $alertService));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Operational Health Check', $tester->getDisplay());
    }

    public function testExecuteReturnsFailureWhenFailureExists(): void
    {
        $service = $this->createMock(OpsHealthCheckService::class);
        $alertService = $this->createMock(OpsHealthAlertService::class);
        $service->expects(self::once())
            ->method('run')
            ->willReturn($this->buildReport(true, 2, 0));
        $alertService->expects(self::once())->method('notifyHealthFailure');
        $alertService->expects(self::never())->method('notifyExecutionFailure');

        $tester = new CommandTester(new OpsHealthCheckCommand($service, $alertService));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
    }

    public function testExecuteOutputsJsonWhenRequested(): void
    {
        $service = $this->createMock(OpsHealthCheckService::class);
        $alertService = $this->createMock(OpsHealthAlertService::class);
        $service->expects(self::once())
            ->method('run')
            ->willReturn($this->buildReport(false, 0, 0));
        $alertService->expects(self::never())->method('notifyHealthFailure');
        $alertService->expects(self::never())->method('notifyExecutionFailure');

        $tester = new CommandTester(new OpsHealthCheckCommand($service, $alertService));
        $exitCode = $tester->execute(['--json' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $decoded = json_decode(trim($tester->getDisplay()), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('checkedAt', $decoded);
        self::assertSame(0, $decoded['failuresCount']);
    }

    public function testExecuteNotifiesExecutionFailureOnCrash(): void
    {
        $service = $this->createMock(OpsHealthCheckService::class);
        $alertService = $this->createMock(OpsHealthAlertService::class);

        $service->expects(self::once())->method('run')->willThrowException(new \RuntimeException('db down'));
        $alertService->expects(self::never())->method('notifyHealthFailure');
        $alertService->expects(self::once())->method('notifyExecutionFailure');

        $tester = new CommandTester(new OpsHealthCheckCommand($service, $alertService));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('crashed unexpectedly', strtolower($tester->getDisplay()));
    }

    /**
     * @return array{
     *     checkedAt: string,
     *     hasFailures: bool,
     *     failuresCount: int,
     *     warningsCount: int,
     *     checks: list<array{key: string, status: string, message: string}>
     * }
     */
    private function buildReport(bool $hasFailures, int $failuresCount, int $warningsCount): array
    {
        return [
            'checkedAt' => '2026-02-26T10:00:00+00:00',
            'hasFailures' => $hasFailures,
            'failuresCount' => $failuresCount,
            'warningsCount' => $warningsCount,
            'checks' => [
                [
                    'key' => 'database',
                    'status' => $hasFailures ? 'fail' : 'ok',
                    'message' => $hasFailures ? 'database error' : 'database reachable',
                ],
            ],
        ];
    }
}
