<?php

namespace App\Tests\Command;

use App\Command\GdprAnonymizeCommand;
use App\Service\GdprDataAnonymizationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GdprAnonymizeCommandTest extends TestCase
{
    public function testExecuteRequiresForceOutsideDryRun(): void
    {
        $service = $this->createMock(GdprDataAnonymizationService::class);
        $service->expects(self::never())->method('anonymizeUser');

        $tester = new CommandTester(new GdprAnonymizeCommand($service));
        $exitCode = $tester->execute([
            '--user-id' => '10',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Use --force', $tester->getDisplay());
    }

    public function testExecuteRunsUserDryRun(): void
    {
        $service = $this->createMock(GdprDataAnonymizationService::class);
        $service->expects(self::once())
            ->method('anonymizeUser')
            ->with(10, true)
            ->willReturn([
                'subjectType' => 'USER',
                'subjectId' => 10,
                'dryRun' => true,
                'summary' => [
                    'userRows' => 1,
                ],
            ]);

        $tester = new CommandTester(new GdprAnonymizeCommand($service));
        $exitCode = $tester->execute([
            '--user-id' => '10',
            '--dry-run' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('DRY_RUN', $tester->getDisplay());
    }

    public function testExecuteRunsCompanyWithForce(): void
    {
        $service = $this->createMock(GdprDataAnonymizationService::class);
        $service->expects(self::once())
            ->method('anonymizeCompany')
            ->with(22, false)
            ->willReturn([
                'subjectType' => 'COMPANY',
                'subjectId' => 22,
                'dryRun' => false,
                'summary' => [
                    'companyRows' => 1,
                ],
            ]);

        $tester = new CommandTester(new GdprAnonymizeCommand($service));
        $exitCode = $tester->execute([
            '--company-id' => '22',
            '--force' => true,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Anonymization executed', $tester->getDisplay());
    }
}
