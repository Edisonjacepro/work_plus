<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class OpsHealthAlertService
{
    private ?string $emailFrom;
    private ?string $emailTo;
    private ?string $webhookUrl;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFrom,
        array $opsHealthAlertConfig,
    ) {
        $this->emailFrom = $this->normalizeOptionalString($opsHealthAlertConfig['email_from'] ?? null);
        $this->emailTo = $this->normalizeOptionalString($opsHealthAlertConfig['email_to'] ?? null);
        $this->webhookUrl = $this->normalizeOptionalString($opsHealthAlertConfig['webhook_url'] ?? null);
    }

    /**
     * @param array{
     *     checkedAt: string,
     *     hasFailures: bool,
     *     failuresCount: int,
     *     warningsCount: int,
     *     checks: list<array{key: string, status: string, message: string}>
     * } $report
     */
    public function notifyHealthFailure(array $report): void
    {
        $context = [
            'checkedAt' => $report['checkedAt'],
            'failuresCount' => $report['failuresCount'],
            'warningsCount' => $report['warningsCount'],
        ];

        $this->logger->critical('Ops health check failed.', $context);
        $this->sendEmail('Ops health check failed', $this->buildFailureMessage($report));
        $this->sendWebhook('ops_health_failure', $context + [
            'checks' => $report['checks'],
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function notifyExecutionFailure(\Throwable $exception, array $context = []): void
    {
        $payload = $context + [
            'exceptionClass' => $exception::class,
            'exceptionMessage' => $exception->getMessage(),
        ];

        $this->logger->critical('Ops health check crashed.', $payload);
        $this->sendEmail(
            'Ops health check crashed',
            sprintf(
                "The ops health check command crashed.\n\nException: %s\nMessage: %s\nContext: %s",
                $exception::class,
                $exception->getMessage(),
                json_encode($context, JSON_UNESCAPED_SLASHES),
            ),
        );
        $this->sendWebhook('ops_health_crash', $payload);
    }

    private function sendEmail(string $subject, string $body): void
    {
        if (null === $this->emailTo) {
            return;
        }

        $fromAddress = $this->emailFrom ?? $this->mailerFrom;

        try {
            $email = (new Email())
                ->from(new Address($fromAddress, 'Work+'))
                ->to($this->emailTo)
                ->subject('[Work+] ' . $subject)
                ->text($body);

            $this->mailer->send($email);
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to send ops health alert email.', [
                'exceptionClass' => $exception::class,
                'exceptionMessage' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendWebhook(string $eventType, array $payload): void
    {
        if (null === $this->webhookUrl) {
            return;
        }

        $body = json_encode([
            'event' => $eventType,
            'source' => 'workplus.ops_health',
            'timestamp' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'payload' => $payload,
        ], JSON_UNESCAPED_SLASHES);
        if (false === $body) {
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 5,
            ],
        ]);

        try {
            $result = @file_get_contents($this->webhookUrl, false, $context);
            $statusLine = $http_response_header[0] ?? null;
            $statusCode = null;
            if (is_string($statusLine) && preg_match('/\s(\d{3})\s/', $statusLine, $matches)) {
                $statusCode = (int) $matches[1];
            }

            if (false === $result || (null !== $statusCode && $statusCode >= 400)) {
                $this->logger->error('Ops health alert webhook failed.', [
                    'statusCode' => $statusCode,
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Ops health alert webhook crashed.', [
                'exceptionClass' => $exception::class,
                'exceptionMessage' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array{
     *     checkedAt: string,
     *     failuresCount: int,
     *     warningsCount: int,
     *     checks: list<array{key: string, status: string, message: string}>
     * } $report
     */
    private function buildFailureMessage(array $report): string
    {
        $lines = [
            'The ops health check command detected failures.',
            'Checked at: ' . $report['checkedAt'],
            'Failures: ' . $report['failuresCount'],
            'Warnings: ' . $report['warningsCount'],
            '',
            'Failed checks:',
        ];

        foreach ($report['checks'] as $check) {
            if ('fail' !== $check['status']) {
                continue;
            }

            $lines[] = sprintf('- %s: %s', $check['key'], $check['message']);
        }

        return implode("\n", $lines);
    }

    private function normalizeOptionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return '' === $normalized ? null : $normalized;
    }
}
