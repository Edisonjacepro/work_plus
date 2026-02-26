<?php

namespace App\Command;

use App\Service\GdprDataRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gdpr:retention',
    description: 'Run GDPR retention policy (purge old data and files).',
)]
class GdprRetentionCommand extends Command
{
    public function __construct(private readonly GdprDataRetentionService $gdprDataRetentionService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview retention impact without deleting data')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Execute retention purge');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRunRequested = true === (bool) $input->getOption('dry-run');
        $forceRequested = true === (bool) $input->getOption('force');

        if ($dryRunRequested && $forceRequested) {
            $io->error('Use either --dry-run or --force, not both.');
            return Command::INVALID;
        }

        $dryRun = !$forceRequested;

        try {
            $report = $this->gdprDataRetentionService->run($dryRun);
        } catch (\Throwable $exception) {
            $io->error('GDPR retention failed: ' . $exception->getMessage());
            return Command::FAILURE;
        }

        $io->title('GDPR Retention');
        $io->writeln(sprintf('Mode: %s', $report['dryRun'] ? 'DRY_RUN' : 'EXECUTED'));
        $io->writeln('Cutoff:');
        foreach ($report['cutoff'] as $key => $value) {
            $io->writeln(sprintf('- %s: %s', $key, $value));
        }
        $io->writeln('Summary:');
        foreach ($report['summary'] as $key => $value) {
            $io->writeln(sprintf('- %s: %d', $key, $value));
        }

        if ($report['dryRun']) {
            $io->success('Retention dry-run completed.');
        } else {
            $io->success('Retention purge executed.');
        }

        return Command::SUCCESS;
    }
}
