<?php

namespace App\Tests\Service;

use App\Service\PointsIntegrityAlertService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class PointsIntegrityAlertServiceTest extends TestCase
{
    public function testNotifyIntegrityFailureLogsAndSendsEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('critical')
            ->with(
                'Points integrity check failed.',
                self::callback(static fn (array $context): bool => 3 === $context['totalIssues']),
            );
        $logger->expects(self::never())->method('error');

        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                return '[Work+] Points integrity check failed' === $email->getSubject();
            }));

        $service = new PointsIntegrityAlertService($mailer, $logger, 'no-reply@workplus.local', [
            'email_from' => 'alerts@workplus.local',
            'email_to' => 'ops@example.test',
            'webhook_url' => null,
        ]);

        $service->notifyIntegrityFailure([
            'checkedAt' => '2026-02-24T16:00:00+00:00',
            'hasIssues' => true,
            'totalIssues' => 3,
            'counts' => [
                'approved_claims_without_credit' => 1,
                'ledger_credits_without_claim' => 2,
            ],
            'samples' => [],
        ]);
    }

    public function testNotifyExecutionFailureWithoutRecipientDoesNotSendEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('critical')
            ->with(
                'Points integrity check crashed.',
                self::callback(static function (array $context): bool {
                    return \RuntimeException::class === $context['exceptionClass']
                        && 'boom' === $context['exceptionMessage'];
                }),
            );
        $logger->expects(self::never())->method('error');

        $mailer->expects(self::never())->method('send');

        $service = new PointsIntegrityAlertService($mailer, $logger, 'no-reply@workplus.local', [
            'email_from' => null,
            'email_to' => null,
            'webhook_url' => null,
        ]);

        $service->notifyExecutionFailure(new \RuntimeException('boom'), ['sampleLimit' => 10]);
    }
}
