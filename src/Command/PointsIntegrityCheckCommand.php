<?php

namespace App\Command;

use App\Service\PointsIntegrityCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:points:integrity:check',
    description: 'Run read-only integrity checks on points claims and ledger entries.',
)]
class PointsIntegrityCheckCommand extends Command
{
    public function __construct(private readonly PointsIntegrityCheckService $pointsIntegrityCheckService)
    {
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

        $report = $this->pointsIntegrityCheckService->run($sampleLimit);

        if (true === (bool) $input->getOption('json')) {
            $io->writeln(json_encode($this->normalizeReport($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
            return Command::FAILURE;
        }

        return Command::SUCCESS;
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
}
