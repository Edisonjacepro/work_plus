<?php

namespace App\Tests\Command;

use App\Command\OpsScheduledCheckCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

class OpsScheduledCheckCommandTest extends TestCase
{
    public function testExecuteReturnsSuccessWhenBothChecksSucceed(): void
    {
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);
        $lockFactory->expects(self::once())->method('createLock')->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $opsRuns = (object) ['count' => 0];
        $pointsRuns = (object) ['count' => 0];
        $sampleCapture = (object) ['value' => null];

        $application = new Application();
        $application->add($this->createProbeCommand('app:ops:health-check', Command::SUCCESS, $opsRuns));
        $application->add($this->createProbeCommand('app:points:integrity:check', Command::SUCCESS, $pointsRuns, $sampleCapture));
        $application->add(new OpsScheduledCheckCommand($lockFactory, 900, 20));

        $tester = new CommandTester($application->find('app:ops:scheduled-check'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(1, $opsRuns->count);
        self::assertSame(1, $pointsRuns->count);
        self::assertSame('20', $sampleCapture->value);
    }

    public function testExecuteReturnsFailureWhenOneCheckFails(): void
    {
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);
        $lockFactory->expects(self::once())->method('createLock')->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $opsRuns = (object) ['count' => 0];
        $pointsRuns = (object) ['count' => 0];

        $application = new Application();
        $application->add($this->createProbeCommand('app:ops:health-check', Command::SUCCESS, $opsRuns));
        $application->add($this->createProbeCommand('app:points:integrity:check', Command::FAILURE, $pointsRuns));
        $application->add(new OpsScheduledCheckCommand($lockFactory, 900, 20));

        $tester = new CommandTester($application->find('app:ops:scheduled-check'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(1, $opsRuns->count);
        self::assertSame(1, $pointsRuns->count);
    }

    public function testExecuteSkipsWhenLockAlreadyAcquired(): void
    {
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);
        $lockFactory->expects(self::once())->method('createLock')->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(false);
        $lock->expects(self::never())->method('release');

        $application = new Application();
        $application->add($this->createProbeCommand('app:ops:health-check', Command::SUCCESS, (object) ['count' => 0]));
        $application->add($this->createProbeCommand('app:points:integrity:check', Command::SUCCESS, (object) ['count' => 0]));
        $application->add(new OpsScheduledCheckCommand($lockFactory, 900, 20));

        $tester = new CommandTester($application->find('app:ops:scheduled-check'));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('already running', strtolower($tester->getDisplay()));
    }

    private function createProbeCommand(
        string $name,
        int $exitCode,
        \stdClass $runCounter,
        ?\stdClass $sampleCapture = null,
    ): Command {
        return new class($name, $exitCode, $runCounter, $sampleCapture) extends Command {
            public function __construct(
                string $name,
                private readonly int $exitCode,
                private readonly \stdClass $runCounter,
                private readonly ?\stdClass $sampleCapture,
            ) {
                parent::__construct($name);
            }

            protected function configure(): void
            {
                $this->addOption('json', null, InputOption::VALUE_NONE);
                $this->addOption('sample-limit', null, InputOption::VALUE_REQUIRED);
            }

            protected function execute(InputInterface $input, OutputInterface $output): int
            {
                ++$this->runCounter->count;
                if (null !== $this->sampleCapture) {
                    $this->sampleCapture->value = $input->getOption('sample-limit');
                }

                return $this->exitCode;
            }
        };
    }
}
