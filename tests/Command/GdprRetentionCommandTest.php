<?php

namespace App\Tests\Command;

use App\Command\GdprRetentionCommand;
use App\Service\GdprDataRetentionService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GdprRetentionCommandTest extends TestCase
{
    public function testExecuteDryRunByDefault(): void
    {
        $service = $this->createMock(GdprDataRetentionService::class);
        $service->expects(self::once())
            ->method('run')
            ->with(true)
            ->willReturn($this->buildReport(true));

        $tester = new CommandTester(new GdprRetentionCommand($service));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('DRY_RUN', $tester->getDisplay());
    }

    public function testExecuteWithForceRunsPurge(): void
    {
        $service = $this->createMock(GdprDataRetentionService::class);
        $service->expects(self::once())
            ->method('run')
            ->with(false)
            ->willReturn($this->buildReport(false));

        $tester = new CommandTester(new GdprRetentionCommand($service));
        $exitCode = $tester->execute(['--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('EXECUTED', $tester->getDisplay());
    }

    public function testExecuteWithBothOptionsFails(): void
    {
        $service = $this->createMock(GdprDataRetentionService::class);
        $service->expects(self::never())->method('run');

        $tester = new CommandTester(new GdprRetentionCommand($service));
        $exitCode = $tester->execute([
            '--dry-run' => true,
            '--force' => true,
        ]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    /**
     * @return array{
     *     dryRun: bool,
     *     cutoff: array{submittedApplications: string, rejectedPointsClaims: string, gdprExportsFiles: string},
     *     summary: array{
     *         submittedApplicationsRows: int,
     *         rejectedPointsClaimsRows: int,
     *         applicationAttachmentFilesRemoved: int,
     *         applicationCvFilesRemoved: int,
     *         gdprExportFilesRemoved: int
     *     }
     * }
     */
    private function buildReport(bool $dryRun): array
    {
        return [
            'dryRun' => $dryRun,
            'cutoff' => [
                'submittedApplications' => '2024-01-01T00:00:00+00:00',
                'rejectedPointsClaims' => '2025-01-01T00:00:00+00:00',
                'gdprExportsFiles' => '2026-01-01T00:00:00+00:00',
            ],
            'summary' => [
                'submittedApplicationsRows' => 2,
                'rejectedPointsClaimsRows' => 3,
                'applicationAttachmentFilesRemoved' => 1,
                'applicationCvFilesRemoved' => 1,
                'gdprExportFilesRemoved' => 4,
            ],
        ];
    }
}
