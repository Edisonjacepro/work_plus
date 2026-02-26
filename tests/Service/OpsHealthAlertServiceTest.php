<?php

namespace App\Tests\Service;

use App\Service\OpsHealthAlertService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class OpsHealthAlertServiceTest extends TestCase
{
    public function testNotifyHealthFailureLogsAndSendsEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('critical')
            ->with(
                'Ops health check failed.',
                self::callback(static fn (array $context): bool => 2 === $context['failuresCount']),
            );
        $logger->expects(self::never())->method('error');

        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(static function (Email $email): bool {
                return '[Work+] Ops health check failed' === $email->getSubject();
            }));

        $service = new OpsHealthAlertService($mailer, $logger, 'no-reply@workplus.local', [
            'email_from' => 'alerts@workplus.local',
            'email_to' => 'ops@example.test',
            'webhook_url' => null,
        ]);

        $service->notifyHealthFailure([
            'checkedAt' => '2026-02-26T10:00:00+00:00',
            'hasFailures' => true,
            'failuresCount' => 2,
            'warningsCount' => 1,
            'checks' => [
                ['key' => 'database', 'status' => 'fail', 'message' => 'database unreachable'],
                ['key' => 'mailer', 'status' => 'warn', 'message' => 'mailer null transport'],
            ],
        ]);
    }

    public function testNotifyExecutionFailureWithoutRecipientDoesNotSendEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('critical')
            ->with(
                'Ops health check crashed.',
                self::callback(static function (array $context): bool {
                    return \RuntimeException::class === $context['exceptionClass']
                        && 'boom' === $context['exceptionMessage'];
                }),
            );
        $logger->expects(self::never())->method('error');

        $mailer->expects(self::never())->method('send');

        $service = new OpsHealthAlertService($mailer, $logger, 'no-reply@workplus.local', [
            'email_from' => null,
            'email_to' => null,
            'webhook_url' => null,
        ]);

        $service->notifyExecutionFailure(new \RuntimeException('boom'), ['command' => 'app:ops:health-check']);
    }
}
