<?php

namespace App\Command;

use App\Service\OpsHealthCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ops:health-check',
    description: 'Run operational health checks (DB, lock, mailer, critical directories).',
)]
class OpsHealthCheckCommand extends Command
{
    public function __construct(private readonly OpsHealthCheckService $opsHealthCheckService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output health check report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $report = $this->opsHealthCheckService->run();

        if (true === (bool) $input->getOption('json')) {
            $io->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $io->title('Operational Health Check');
            $io->writeln('Checked at: ' . $report['checkedAt']);
            $io->writeln('Failures: ' . (string) $report['failuresCount']);
            $io->writeln('Warnings: ' . (string) $report['warningsCount']);
            $io->newLine();

            foreach ($report['checks'] as $check) {
                $io->writeln(sprintf('- [%s] %s: %s', strtoupper($check['status']), $check['key'], $check['message']));
            }
        }

        return $report['hasFailures'] ? Command::FAILURE : Command::SUCCESS;
    }
}
