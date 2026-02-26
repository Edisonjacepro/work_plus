<?php

namespace App\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

class GdprDataRetentionService
{
    private int $applicationsSubmittedDays;
    private int $pointsClaimsRejectedDays;
    private int $exportsFilesDays;

    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
        private readonly string $applicationAttachmentDir,
        private readonly string $cvUploadDir,
        private readonly string $gdprExportDir,
        array $gdprRetentionPolicy,
    ) {
        $this->applicationsSubmittedDays = max(1, (int) ($gdprRetentionPolicy['applications_submitted_days'] ?? 730));
        $this->pointsClaimsRejectedDays = max(1, (int) ($gdprRetentionPolicy['points_claims_rejected_days'] ?? 365));
        $this->exportsFilesDays = max(1, (int) ($gdprRetentionPolicy['exports_files_days'] ?? 30));
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
    public function run(bool $dryRun = true, ?\DateTimeImmutable $now = null): array
    {
        $referenceNow = $now ?? new \DateTimeImmutable();
        $submittedApplicationsCutoff = $referenceNow->modify(sprintf('-%d days', $this->applicationsSubmittedDays));
        $rejectedPointsClaimsCutoff = $referenceNow->modify(sprintf('-%d days', $this->pointsClaimsRejectedDays));
        $gdprExportsFilesCutoff = $referenceNow->modify(sprintf('-%d days', $this->exportsFilesDays));

        $staleApplications = $this->connection->fetchAllAssociative(
            "SELECT id, cv_file_path FROM application WHERE status = 'SUBMITTED' AND created_at < :cutoff",
            ['cutoff' => $submittedApplicationsCutoff],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );
        $applicationIds = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $staleApplications);
        $applicationIds = array_values(array_filter($applicationIds, static fn (int $id): bool => $id > 0));

        $staleAttachmentNames = [];
        if ([] !== $applicationIds) {
            $staleAttachmentNames = $this->connection->fetchFirstColumn(
                <<<'SQL'
                    SELECT aa.stored_name
                    FROM application_attachment aa
                    INNER JOIN application_message am ON am.id = aa.message_id
                    WHERE am.application_id IN (:applicationIds)
                    SQL,
                ['applicationIds' => $applicationIds],
                ['applicationIds' => ArrayParameterType::INTEGER],
            );
        }

        $rejectedPointsClaimsRows = (int) $this->connection->fetchOne(
            "SELECT COUNT(id) FROM points_claim WHERE status = 'REJECTED' AND created_at < :cutoff",
            ['cutoff' => $rejectedPointsClaimsCutoff],
            ['cutoff' => Types::DATETIME_IMMUTABLE],
        );

        $cvFilePaths = [];
        foreach ($staleApplications as $row) {
            $cvPath = $row['cv_file_path'] ?? null;
            if (!is_string($cvPath)) {
                continue;
            }
            $trimmed = trim($cvPath);
            if ('' !== $trimmed) {
                $cvFilePaths[] = $trimmed;
            }
        }

        $attachmentFilePaths = $this->buildApplicationAttachmentPaths($staleAttachmentNames);
        $cvAbsolutePaths = $this->buildCvPaths($cvFilePaths);
        $exportFilePaths = $this->findFilesOlderThan($this->gdprExportDir, $gdprExportsFilesCutoff);

        $summary = [
            'submittedApplicationsRows' => count($applicationIds),
            'rejectedPointsClaimsRows' => $rejectedPointsClaimsRows,
            'applicationAttachmentFilesRemoved' => count($attachmentFilePaths),
            'applicationCvFilesRemoved' => count($cvAbsolutePaths),
            'gdprExportFilesRemoved' => count($exportFilePaths),
        ];

        if (false === $dryRun) {
            $this->connection->beginTransaction();
            try {
                $summary['submittedApplicationsRows'] = $this->connection->executeStatement(
                    "DELETE FROM application WHERE status = 'SUBMITTED' AND created_at < :cutoff",
                    ['cutoff' => $submittedApplicationsCutoff],
                    ['cutoff' => Types::DATETIME_IMMUTABLE],
                );
                $summary['rejectedPointsClaimsRows'] = $this->connection->executeStatement(
                    "DELETE FROM points_claim WHERE status = 'REJECTED' AND created_at < :cutoff",
                    ['cutoff' => $rejectedPointsClaimsCutoff],
                    ['cutoff' => Types::DATETIME_IMMUTABLE],
                );
                $this->connection->commit();
            } catch (\Throwable $exception) {
                $this->connection->rollBack();
                throw $exception;
            }

            $summary['applicationAttachmentFilesRemoved'] = $this->deleteFiles($attachmentFilePaths);
            $summary['applicationCvFilesRemoved'] = $this->deleteFiles($cvAbsolutePaths);
            $summary['gdprExportFilesRemoved'] = $this->deleteFiles($exportFilePaths);
        }

        $this->logger->info('GDPR retention run completed.', [
            'dryRun' => $dryRun,
            'summary' => $summary,
            'cutoff' => [
                'submittedApplications' => $submittedApplicationsCutoff->format(DATE_ATOM),
                'rejectedPointsClaims' => $rejectedPointsClaimsCutoff->format(DATE_ATOM),
                'gdprExportsFiles' => $gdprExportsFilesCutoff->format(DATE_ATOM),
            ],
        ]);

        return [
            'dryRun' => $dryRun,
            'cutoff' => [
                'submittedApplications' => $submittedApplicationsCutoff->format(DATE_ATOM),
                'rejectedPointsClaims' => $rejectedPointsClaimsCutoff->format(DATE_ATOM),
                'gdprExportsFiles' => $gdprExportsFilesCutoff->format(DATE_ATOM),
            ],
            'summary' => $summary,
        ];
    }

    /**
     * @param list<int|string> $storedNames
     * @return list<string>
     */
    private function buildApplicationAttachmentPaths(array $storedNames): array
    {
        $paths = [];
        foreach ($storedNames as $storedNameRaw) {
            if (!is_string($storedNameRaw)) {
                continue;
            }

            $storedName = trim($storedNameRaw);
            if ('' === $storedName || basename($storedName) !== $storedName) {
                continue;
            }

            $paths[] = rtrim($this->applicationAttachmentDir, '/\\') . DIRECTORY_SEPARATOR . $storedName;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param list<string> $storedCvPaths
     * @return list<string>
     */
    private function buildCvPaths(array $storedCvPaths): array
    {
        $paths = [];
        foreach ($storedCvPaths as $storedCvPath) {
            $normalized = trim($storedCvPath);
            if ('' === $normalized) {
                continue;
            }

            $baseName = basename($normalized);
            if ($baseName !== $normalized) {
                $baseName = basename(str_replace('\\', '/', $normalized));
            }

            if ('' === $baseName || '.' === $baseName || '..' === $baseName) {
                continue;
            }

            $paths[] = rtrim($this->cvUploadDir, '/\\') . DIRECTORY_SEPARATOR . $baseName;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private function findFilesOlderThan(string $directory, \DateTimeImmutable $cutoff): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*');
        if (false === $files) {
            return [];
        }

        $staleFiles = [];
        foreach ($files as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $mtime = @filemtime($filePath);
            if (false === $mtime) {
                continue;
            }

            if ($mtime < $cutoff->getTimestamp()) {
                $staleFiles[] = $filePath;
            }
        }

        return $staleFiles;
    }

    /**
     * @param list<string> $files
     */
    private function deleteFiles(array $files): int
    {
        $deleted = 0;
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            if (@unlink($file)) {
                ++$deleted;
                continue;
            }

            $this->logger->warning('GDPR retention could not remove file.', [
                'file' => $file,
            ]);
        }

        return $deleted;
    }
}
