<?php

namespace App\Command;

use App\Service\PointsIntegrityAlertService;
use App\Service\PointsIntegrityCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

#[AsCommand(
    name: 'app:points:integrity:check',
    description: 'Run read-only integrity checks on points claims and ledger entries.',
)]
class PointsIntegrityCheckCommand extends Command
{
    private const LOCK_KEY = 'points_integrity_check_command';

    public function __construct(
        private readonly PointsIntegrityCheckService $pointsIntegrityCheckService,
        private readonly PointsIntegrityAlertService $pointsIntegrityAlertService,
        private readonly LockFactory $lockFactory,
        private readonly int $pointsIntegrityLockTtlSeconds,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sample-limit', null, InputOption::VALUE_REQUIRED, 'Sample size returned for each issue category.', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output report as JSON.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $sampleLimit = max(1, (int) $input->getOption('sample-limit'));
        $lock = $this->lockFactory->createLock(self::LOCK_KEY, (float) $this->pointsIntegrityLockTtlSeconds, true);
        if (!$lock->acquire(false)) {
            $io->warning('Integrity check already running. Skipping this execution.');
            return Command::SUCCESS;
        }

        try {
            $report = $this->pointsIntegrityCheckService->run($sampleLimit);
            $normalizedReport = $this->normalizeReport($report);

            if (true === (bool) $input->getOption('json')) {
                $io->writeln(json_encode($normalizedReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->title('Points Integrity Check');
                $io->writeln('Checked at: ' . $report['checkedAt']->format(DATE_ATOM));
                $io->writeln('Total issues: ' . (string) $report['totalIssues']);
                $io->newLine();

                foreach ($report['counts'] as $issueKey => $count) {
                    $io->writeln(sprintf('- %s: %d', $issueKey, $count));
                    if ($count > 0) {
                        $io->writeln('  sample: ' . json_encode($report['samples'][$issueKey], JSON_UNESCAPED_SLASHES));
                    }
                }
            }

            if (true === $report['hasIssues']) {
                $this->pointsIntegrityAlertService->notifyIntegrityFailure($normalizedReport);
                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $this->pointsIntegrityAlertService->notifyExecutionFailure($exception, [
                'sampleLimit' => $sampleLimit,
            ]);
            $io->error('Points integrity check crashed unexpectedly.');
            return Command::FAILURE;
        } finally {
            $this->releaseLockSafely($lock);
        }
    }

    /**
     * @param array{
     *     checkedAt: \DateTimeImmutable,
     *     hasIssues: bool,
     *     totalIssues: int,
     *     counts: array<string, int>,
     *     samples: array<string, list<array<string, mixed>>>
     * } $report
     *
     * @return array{
     *     checkedAt: string,
     *     hasIssues: bool,
     *     totalIssues: int,
     *     counts: array<string, int>,
     *     samples: array<string, list<array<string, mixed>>>
     * }
     */
    private function normalizeReport(array $report): array
    {
        return [
            'checkedAt' => $report['checkedAt']->format(DATE_ATOM),
            'hasIssues' => $report['hasIssues'],
            'totalIssues' => $report['totalIssues'],
            'counts' => $report['counts'],
            'samples' => $report['samples'],
        ];
    }

    private function releaseLockSafely(LockInterface $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable) {
        }
    }
}
