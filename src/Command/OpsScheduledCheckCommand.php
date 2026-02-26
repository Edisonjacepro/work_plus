<?php

namespace App\Command;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

#[AsCommand(
    name: 'app:ops:scheduled-check',
    description: 'Run scheduled ops checks (ops health + points integrity).',
)]
class OpsScheduledCheckCommand extends Command
{
    private const LOCK_KEY = 'ops_scheduled_check_command';

    public function __construct(
        private readonly LockFactory $lockFactory,
        private readonly int $opsHealthLockTtlSeconds,
        private readonly int $opsHealthSchedulerPointsSampleLimit,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, (float) $this->opsHealthLockTtlSeconds, true);
        if (!$lock->acquire(false)) {
            $io->warning('Scheduled checks already running. Skipping this execution.');

            return Command::SUCCESS;
        }

        try {
            $application = $this->getApplication();
            if (!$application instanceof Application) {
                $io->error('Unable to access console application.');

                return Command::FAILURE;
            }

            $io->title('Scheduled Operations Check');

            $opsResult = $this->runSubCommand($application, 'app:ops:health-check', [
                '--json' => true,
            ]);
            $this->printResult($io, 'ops_health', $opsResult);

            $pointsResult = $this->runSubCommand($application, 'app:points:integrity:check', [
                '--json' => true,
                '--sample-limit' => (string) $this->opsHealthSchedulerPointsSampleLimit,
            ]);
            $this->printResult($io, 'points_integrity', $pointsResult);

            if (Command::SUCCESS !== $opsResult['exitCode'] || Command::SUCCESS !== $pointsResult['exitCode']) {
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } finally {
            $this->releaseLockSafely($lock);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{
     *     exitCode: int,
     *     output: string
     * }
     */
    private function runSubCommand(Application $application, string $commandName, array $arguments): array
    {
        $command = $application->find($commandName);
        $input = new ArrayInput(['command' => $commandName] + $arguments);
        $input->setInteractive(false);
        $buffer = new BufferedOutput();
        $exitCode = $command->run($input, $buffer);

        return [
            'exitCode' => $exitCode,
            'output' => trim($buffer->fetch()),
        ];
    }

    /**
     * @param array{
     *     exitCode: int,
     *     output: string
     * } $result
     */
    private function printResult(SymfonyStyle $io, string $label, array $result): void
    {
        $io->writeln(sprintf('- %s exit code: %d', $label, $result['exitCode']));

        if ('' !== $result['output'] && $io->isVerbose()) {
            $io->writeln($result['output']);
        }
    }

    private function releaseLockSafely(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable) {
        }
    }
}
