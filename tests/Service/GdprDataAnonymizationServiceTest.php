<?php

namespace App\Tests\Service;

use App\Service\GdprDataAnonymizationService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GdprDataAnonymizationServiceTest extends TestCase
{
    public function testAnonymizeUserDryRunReturnsSummaryWithoutWrite(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['id' => 8]);
        $connection->expects(self::exactly(7))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql): int {
                if (str_contains($sql, 'FROM application WHERE candidate_id')) {
                    return 2;
                }
                if (str_contains($sql, 'FROM application_message')) {
                    return 3;
                }

                return 1;
            });
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('executeStatement');
        $logger->expects(self::once())->method('info');

        $service = new GdprDataAnonymizationService($connection, $logger);
        $result = $service->anonymizeUser(8, true);

        self::assertTrue($result['dryRun']);
        self::assertSame('USER', $result['subjectType']);
        self::assertSame(2, $result['summary']['applicationsRows']);
    }

    public function testAnonymizeUserExecutesInTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['id' => 8]);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection->expects(self::never())->method('rollBack');
        $connection->expects(self::exactly(8))
            ->method('executeStatement')
            ->willReturn(1);
        $logger->expects(self::once())->method('warning');

        $service = new GdprDataAnonymizationService($connection, $logger);
        $result = $service->anonymizeUser(8, false);

        self::assertFalse($result['dryRun']);
        self::assertSame('USER', $result['subjectType']);
        self::assertSame(1, $result['summary']['userRows']);
    }

    public function testAnonymizeCompanyDryRunUsesCounters(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(['id' => 5]);
        $connection->expects(self::once())
            ->method('fetchFirstColumn')
            ->willReturn([100, 101]);
        $connection->expects(self::exactly(2))
            ->method('fetchOne')
            ->willReturnCallback(static function (string $sql): int {
                if (str_contains($sql, 'FROM offer')) {
                    return 4;
                }

                return 2;
            });
        $connection->expects(self::never())->method('beginTransaction');
        $connection->expects(self::never())->method('executeStatement');
        $logger->expects(self::once())->method('info');

        $service = new GdprDataAnonymizationService($connection, $logger);
        $result = $service->anonymizeCompany(5, true);

        self::assertTrue($result['dryRun']);
        self::assertSame('COMPANY', $result['subjectType']);
        self::assertSame(4, $result['summary']['offersRows']);
        self::assertSame(2, $result['summary']['companyUsers']);
    }
}
