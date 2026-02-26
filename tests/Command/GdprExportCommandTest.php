<?php

namespace App\Tests\Command;

use App\Command\GdprExportCommand;
use App\Service\GdprDataExportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class GdprExportCommandTest extends TestCase
{
    public function testExecuteFailsWhenNoSubjectOptionProvided(): void
    {
        $service = $this->createMock(GdprDataExportService::class);
        $service->expects(self::never())->method('exportUser');
        $service->expects(self::never())->method('exportCompany');

        $tester = new CommandTester(new GdprExportCommand($service, sys_get_temp_dir()));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::INVALID, $exitCode);
    }

    public function testExecuteExportsUserToJsonFile(): void
    {
        $service = $this->createMock(GdprDataExportService::class);
        $service->expects(self::once())
            ->method('exportUser')
            ->with(9)
            ->willReturn([
                'generatedAt' => '2026-02-25T10:00:00+00:00',
                'schemaVersion' => 'gdpr_export_v1',
                'subject' => ['type' => 'USER', 'id' => 9],
                'data' => ['user' => ['id' => 9]],
            ]);

        $outputDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wp-gdpr-export-' . bin2hex(random_bytes(4));

        $tester = new CommandTester(new GdprExportCommand($service, $outputDir));
        $exitCode = $tester->execute([
            '--user-id' => '9',
            '--output-dir' => $outputDir,
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('GDPR export created:', $tester->getDisplay());

        $files = glob($outputDir . DIRECTORY_SEPARATOR . '*.json');
        self::assertIsArray($files);
        self::assertCount(1, $files);

        if (is_array($files) && 1 === count($files) && is_string($files[0])) {
            $decoded = json_decode((string) file_get_contents($files[0]), true);
            self::assertIsArray($decoded);
            self::assertSame(9, $decoded['subject']['id']);
            @unlink($files[0]);
        }
        @rmdir($outputDir);
    }
}
