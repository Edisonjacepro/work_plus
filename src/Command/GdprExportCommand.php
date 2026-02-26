<?php

namespace App\Command;

use App\Service\GdprDataExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:gdpr:export',
    description: 'Export DSAR data for a user or a company into a JSON file.',
)]
class GdprExportCommand extends Command
{
    public function __construct(
        private readonly GdprDataExportService $gdprDataExportService,
        private readonly string $gdprExportDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('user-id', null, InputOption::VALUE_REQUIRED, 'User ID to export')
            ->addOption('company-id', null, InputOption::VALUE_REQUIRED, 'Company ID to export')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Target directory for generated export files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $selection = $this->resolveSubjectSelection($input);
        if (null === $selection) {
            $io->error('Provide exactly one option: --user-id or --company-id.');
            return Command::INVALID;
        }

        $targetDir = trim((string) ($input->getOption('output-dir') ?? ''));
        if ('' === $targetDir) {
            $targetDir = $this->gdprExportDir;
        }

        try {
            $report = 'USER' === $selection['subjectType']
                ? $this->gdprDataExportService->exportUser($selection['subjectId'])
                : $this->gdprDataExportService->exportCompany($selection['subjectId']);

            if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
                throw new \RuntimeException('Unable to create export directory.');
            }

            $timestamp = (new \DateTimeImmutable())->format('Ymd_His');
            $fileName = sprintf(
                'gdpr_export_%s_%d_%s.json',
                strtolower($selection['subjectType']),
                $selection['subjectId'],
                $timestamp,
            );
            $path = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $fileName;

            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded) || false === @file_put_contents($path, $encoded)) {
                throw new \RuntimeException('Unable to write export file.');
            }

            $io->success(sprintf('GDPR export created: %s', $path));
            return Command::SUCCESS;
        } catch (\Throwable $exception) {
            $io->error('GDPR export failed: ' . $exception->getMessage());
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
