<?php

namespace App\Tests\Service;

use App\Service\OpsHealthCheckService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

class OpsHealthCheckServiceTest extends TestCase
{
    public function testRunReturnsSuccessWithWarnings(): void
    {
        $existingDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wp-ops-existing-' . bin2hex(random_bytes(4));
        $missingButCreatableDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wp-ops-missing-' . bin2hex(random_bytes(4));
        @mkdir($existingDir, 0775, true);

        $connection = $this->createMock(Connection::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);

        $connection->expects(self::once())->method('fetchOne')->with('SELECT 1')->willReturn(1);
        $lockFactory->expects(self::once())->method('createLock')->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $service = new OpsHealthCheckService(
            $connection,
            $lockFactory,
            'null://null',
            $existingDir,
            $existingDir,
            $missingButCreatableDir,
        );

        $report = $service->run();

        self::assertFalse($report['hasFailures']);
        self::assertGreaterThanOrEqual(1, $report['warningsCount']);
        self::assertSame(0, $report['failuresCount']);

        @rmdir($existingDir);
    }

    public function testRunReturnsFailureWhenDatabaseFails(): void
    {
        $existingDir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'wp-ops-existing-' . bin2hex(random_bytes(4));
        @mkdir($existingDir, 0775, true);

        $connection = $this->createMock(Connection::class);
        $lockFactory = $this->createMock(LockFactory::class);
        $lock = $this->createMock(LockInterface::class);

        $connection->expects(self::once())->method('fetchOne')->willThrowException(new \RuntimeException('db down'));
        $lockFactory->expects(self::once())->method('createLock')->willReturn($lock);
        $lock->expects(self::once())->method('acquire')->with(false)->willReturn(true);
        $lock->expects(self::once())->method('release');

        $service = new OpsHealthCheckService(
            $connection,
            $lockFactory,
            'smtp://example.test',
            $existingDir,
            $existingDir,
            'Z:\\path\\that\\does\\not\\exist\\ops',
        );

        $report = $service->run();

        self::assertTrue($report['hasFailures']);
        self::assertGreaterThanOrEqual(1, $report['failuresCount']);
        self::assertContains('database', array_map(static fn (array $check): string => $check['key'], $report['checks']));

        @rmdir($existingDir);
    }
}
