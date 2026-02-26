<?php

namespace App\Tests\Service;

use App\Service\GdprDataExportService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GdprDataExportServiceTest extends TestCase
{
    public function testExportUserThrowsWhenSubjectDoesNotExist(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn(false);
        $logger->expects(self::never())->method('info');

        $service = new GdprDataExportService($connection, $logger);

        $this->expectException(\RuntimeException::class);
        $service->exportUser(999);
    }

    public function testExportUserBuildsReport(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 12,
                'email' => 'test@example.test',
                'roles' => '["ROLE_USER"]',
                'company_id' => 5,
            ]);
        $connection->expects(self::exactly(5))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'FROM offer')) {
                    return [['id' => 50, 'title' => 'Offer']];
                }
                if (str_contains($sql, 'FROM application WHERE candidate_id')) {
                    return [['id' => 70, 'message' => 'Candidate message']];
                }
                if (str_contains($sql, 'FROM application_message')) {
                    return [['id' => 71, 'body' => 'Thread message']];
                }
                if (str_contains($sql, 'FROM points_ledger_entry')) {
                    return [['id' => 72, 'metadata' => '{"source":"test"}']];
                }
                if (str_contains($sql, 'FROM points_claim WHERE reviewed_by_id')) {
                    return [['id' => 73, 'evidence_documents' => '[{"valid":true}]', 'external_checks' => '{"coherenceOk":true}']];
                }

                return [];
            });
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'GDPR export generated.',
                self::callback(static fn (array $context): bool => 'USER' === $context['subjectType'] && 12 === $context['subjectId']),
            );

        $service = new GdprDataExportService($connection, $logger);
        $report = $service->exportUser(12);

        self::assertSame('USER', $report['subject']['type']);
        self::assertSame(12, $report['subject']['id']);
        self::assertSame(['ROLE_USER'], $report['data']['user']['roles']);
        self::assertSame('test', $report['data']['pointsLedgerEntries'][0]['metadata']['source']);
    }

    public function testExportCompanyBuildsReport(): void
    {
        $connection = $this->createMock(Connection::class);
        $logger = $this->createMock(LoggerInterface::class);

        $connection->expects(self::once())
            ->method('fetchAssociative')
            ->willReturn([
                'id' => 5,
                'name' => 'Company A',
            ]);
        $connection->expects(self::exactly(6))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'FROM "user"')) {
                    return [['id' => 1, 'roles' => '["ROLE_USER","ROLE_ADMIN"]']];
                }
                if (str_contains($sql, 'FROM offer')) {
                    return [['id' => 2, 'impact_categories' => '["CLIMATE"]']];
                }
                if (str_contains($sql, 'FROM points_claim')) {
                    return [['id' => 3, 'evidence_documents' => '[{"valid":true}]', 'external_checks' => '{"ok":true}']];
                }
                if (str_contains($sql, 'FROM points_ledger_entry')) {
                    return [['id' => 4, 'metadata' => '{"foo":"bar"}']];
                }
                if (str_contains($sql, 'FROM points_policy_decision')) {
                    return [['id' => 5, 'metadata' => '{"rule":"v1"}']];
                }
                if (str_contains($sql, 'FROM recruiter_subscription_payment')) {
                    return [['id' => 6, 'provider_payload' => '{"provider":"fake"}']];
                }

                return [];
            });
        $logger->expects(self::once())
            ->method('info')
            ->with(
                'GDPR export generated.',
                self::callback(static fn (array $context): bool => 'COMPANY' === $context['subjectType'] && 5 === $context['subjectId']),
            );

        $service = new GdprDataExportService($connection, $logger);
        $report = $service->exportCompany(5);

        self::assertSame('COMPANY', $report['subject']['type']);
        self::assertSame(5, $report['subject']['id']);
        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $report['data']['users'][0]['roles']);
        self::assertSame(['CLIMATE'], $report['data']['offers'][0]['impact_categories']);
        self::assertSame('bar', $report['data']['pointsLedgerEntries'][0]['metadata']['foo']);
    }
}
