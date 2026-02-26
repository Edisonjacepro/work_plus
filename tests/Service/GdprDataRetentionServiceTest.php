<?php

namespace App\Tests\Service;

use App\Service\GdprDataRetentionService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GdprDataRetentionServiceTest extends TestCase
{
    public function testRunDryRunReturnsSummaryWithoutDeleting(): void
    {
        [$attachmentDir, $cvDir, $exportDir] = $this->createTempDirectories();
        $attachmentOld = $attachmentDir . DIRECTORY_SEPARATOR . 'attachment-old.pdf';
        $cvOld = $cvDir . DIRECTORY_SEPARATOR . 'cv-old.pdf';
        $exportOld = $exportDir . DIRECTORY_SEPARATOR . 'export-old.json';
        file_put_contents($attachmentOld, 'x');
        file_put_contents($cvOld, 'y');
        file_put_contents($exportOld, '{}');
        touch($attachmentOld, strtotime('2026-01-01 00:00:00'));
        touch($cvOld, strtotime('2026-01-01 00:00:00'));
        touch($exportOld, strtotime('2026-01-01 00:00:00'));

        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['id' => 10, 'cv_file_path' => 'cv-old.pdf'],
            ]);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->willReturn(['attachment-old.pdf']);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(4);
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('executeStatement');
        $logger->expects(self::once())->method('info');

        $service = new GdprDataRetentionService(
            $connection,
            $logger,
            $attachmentDir,
            $cvDir,
            $exportDir,
            [
                'applications_submitted_days' => 30,
                'points_claims_rejected_days' => 30,
                'exports_files_days' => 30,
            ],
        );

        $report = $service->run(true, new \DateTimeImmutable('2026-03-01 00:00:00'));

        self::assertTrue($report['dryRun']);
        self::assertSame(1, $report['summary']['submittedApplicationsRows']);
        self::assertSame(4, $report['summary']['rejectedPointsClaimsRows']);
        self::assertSame(1, $report['summary']['applicationAttachmentFilesRemoved']);
        self::assertSame(1, $report['summary']['applicationCvFilesRemoved']);
        self::assertSame(1, $report['summary']['gdprExportFilesRemoved']);
        self::assertFileExists($attachmentOld);
        self::assertFileExists($cvOld);
        self::assertFileExists($exportOld);

        @unlink($attachmentOld);
        @unlink($cvOld);
        @unlink($exportOld);
        @rmdir($attachmentDir);
        @rmdir($cvDir);
        @rmdir($exportDir);
    }

    public function testRunForceDeletesRowsAndFiles(): void
    {
        [$attachmentDir, $cvDir, $exportDir] = $this->createTempDirectories();
        $attachmentOld = $attachmentDir . DIRECTORY_SEPARATOR . 'attachment-old.pdf';
        $cvOld = $cvDir . DIRECTORY_SEPARATOR . 'cv-old.pdf';
        $exportOld = $exportDir . DIRECTORY_SEPARATOR . 'export-old.json';
        file_put_contents($attachmentOld, 'x');
        file_put_contents($cvOld, 'y');
        file_put_contents($exportOld, '{}');
        touch($attachmentOld, strtotime('2026-01-01 00:00:00'));
        touch($cvOld, strtotime('2026-01-01 00:00:00'));
        touch($exportOld, strtotime('2026-01-01 00:00:00'));

        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAllAssociative')
            ->willReturn([
                ['id' => 20, 'cv_file_path' => 'nested/path/cv-old.pdf'],
            ]);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->willReturn(['attachment-old.pdf']);
        $connection->expects(self::once())
            ->method('fetchOne')
            ->willReturn(5);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::exactly(2))
            ->method('executeStatement')
            ->willReturnOnConsecutiveCalls(3, 2);
        $logger->expects(self::once())->method('info');
        $logger->expects(self::never())->method('warning');

        $service = new GdprDataRetentionService(
            $connection,
            $logger,
            $attachmentDir,
            $cvDir,
            $exportDir,
            [
                'applications_submitted_days' => 30,
                'points_claims_rejected_days' => 30,
                'exports_files_days' => 30,
            ],
        );

        $report = $service->run(false, new \DateTimeImmutable('2026-03-01 00:00:00'));

        self::assertFalse($report['dryRun']);
        self::assertSame(3, $report['summary']['submittedApplicationsRows']);
        self::assertSame(2, $report['summary']['rejectedPointsClaimsRows']);
        self::assertSame(1, $report['summary']['applicationAttachmentFilesRemoved']);
        self::assertSame(1, $report['summary']['applicationCvFilesRemoved']);
        self::assertSame(1, $report['summary']['gdprExportFilesRemoved']);
        self::assertFileDoesNotExist($attachmentOld);
        self::assertFileDoesNotExist($cvOld);
        self::assertFileDoesNotExist($exportOld);

        @rmdir($attachmentDir);
        @rmdir($cvDir);
        @rmdir($exportDir);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function createTempDirectories(): array
    {
        $base = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wp-retention-' . bin2hex(random_bytes(4));
        $attachmentDir = $base . DIRECTORY_SEPARATOR . 'attachments';
        $cvDir = $base . DIRECTORY_SEPARATOR . 'cv';
        $exportDir = $base . DIRECTORY_SEPARATOR . 'exports';

        @mkdir($attachmentDir, 0775, true);
        @mkdir($cvDir, 0775, true);
        @mkdir($exportDir, 0775, true);

        return [$attachmentDir, $cvDir, $exportDir];
    }
}
