<?php

namespace App\Command;

use App\Service\GdprDataAnonymizationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gdpr:anonymize',
    description: 'Anonymize personal data for a user or a company.',
)]
class GdprAnonymizeCommand extends Command
{
    public function __construct(private readonly GdprDataAnonymizationService $gdprDataAnonymizationService)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User ID to anonymize')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company ID to anonymize')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview anonymization impact without writing changes')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Execute anonymization changes (required without --dry-run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $selection = $this->resolveSubjectSelection($input);
        if (null === $selection) {
            $io->error('Provide exactly one option: --user-id or --company-id.');
            return Command::INVALID;
        }

        $dryRun = true === (bool) $input->getOption('dry-run');
        $force = true === (bool) $input->getOption('force');
        if (!$dryRun && !$force) {
            $io->error('Use --force to execute anonymization. Use --dry-run to preview.');
            return Command::INVALID;
        }

        try {
            $result = 'USER' === $selection['subjectType']
                ? $this->gdprDataAnonymizationService->anonymizeUser($selection['subjectId'], $dryRun)
                : $this->gdprDataAnonymizationService->anonymizeCompany($selection['subjectId'], $dryRun);

            $io->title('GDPR Anonymization');
            $io->writeln(sprintf('Subject: %s #%d', $result['subjectType'], $result['subjectId']));
            $io->writeln(sprintf('Mode: %s', $result['dryRun'] ? 'DRY_RUN' : 'EXECUTED'));
            $io->writeln('Summary:');
            foreach ($result['summary'] as $key => $value) {
                $io->writeln(sprintf('- %s: %d', $key, $value));
            }

            if ($result['dryRun']) {
                $io->success('Dry-run completed.');
            } else {
                $io->success('Anonymization executed.');
            }

            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error('GDPR anonymization failed: ' . $exception->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * @return array{subjectType: 'USER'|'COMPANY', subjectId: int}|null
     */
    private function resolveSubjectSelection(InputInterface $input): ?array
    {
        $userIdRaw = $input->getOption('user-id');
        $companyIdRaw = $input->getOption('company-id');

        $hasUser = is_string($userIdRaw) && '' !== trim($userIdRaw);
        $hasCompany = is_string($companyIdRaw) && '' !== trim($companyIdRaw);
        if ($hasUser === $hasCompany) {
            return null;
        }

        if ($hasUser) {
            return [
                'subjectType' => 'USER',
                'subjectId' => max(0, (int) $userIdRaw),
            ];
        }

        return [
            'subjectType' => 'COMPANY',
            'subjectId' => max(0, (int) $companyIdRaw),
        ];
    }
}
