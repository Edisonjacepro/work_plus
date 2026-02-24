<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class PointsIntegrityAlertService
{
    private ?string $emailFrom;
    private ?string $emailTo;
    private ?string $webhookUrl;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $mailerFrom,
        array $pointsIntegrityAlertConfig,
    ) {
        $this->emailFrom = $this->normalizeOptionalString($pointsIntegrityAlertConfig['email_from'] ?? null);
        $this->emailTo = $this->normalizeOptionalString($pointsIntegrityAlertConfig['email_to'] ?? null);
        $this->webhookUrl = $this->normalizeOptionalString($pointsIntegrityAlertConfig['webhook_url'] ?? null);
    }

    /**
     * @param array{
     *     checkedAt: string,
     *     hasIssues: bool,
     *     totalIssues: int,
     *     counts: array<string, int>,
     *     samples: array<string, list<array<string, mixed>>>
     * } $report
     */
    public function notifyIntegrityFailure(array $report): void
    {
        $context = [
            'checkedAt' => $report['checkedAt'],
            'totalIssues' => $report['totalIssues'],
            'counts' => $report['counts'],
        ];

        $this->logger->critical('Points integrity check failed.', $context);
        $this->sendEmail('Points integrity check failed', $this->buildFailureMessage($report));
        $this->sendWebhook('points_integrity_failure', $context + [
            'samples' => $report['samples'],
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

        $this->logger->critical('Points integrity check crashed.', $payload);
        $this->sendEmail(
            'Points integrity check crashed',
            sprintf(
                "The points integrity command crashed.\n\nException: %s\nMessage: %s\nContext: %s",
                $exception::class,
                $exception->getMessage(),
                json_encode($context, JSON_UNESCAPED_SLASHES),
            ),
        );
        $this->sendWebhook('points_integrity_crash', $payload);
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
            $this->logger->error('Unable to send points integrity alert email.', [
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
            'source' => 'workplus.points_integrity',
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
                $this->logger->error('Points integrity alert webhook failed.', [
                    'statusCode' => $statusCode,
                ]);
            }
        } catch (\Throwable $exception) {
            $this->logger->error('Points integrity alert webhook crashed.', [
                'exceptionClass' => $exception::class,
                'exceptionMessage' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param array{
     *     checkedAt: string,
     *     totalIssues: int,
     *     counts: array<string, int>
     * } $report
     */
    private function buildFailureMessage(array $report): string
    {
        $lines = [
            'The points integrity command detected anomalies.',
            'Checked at: ' . $report['checkedAt'],
            'Total issues: ' . $report['totalIssues'],
            '',
            'Counts:',
        ];

        foreach ($report['counts'] as $issueKey => $count) {
            $lines[] = sprintf('- %s: %d', $issueKey, $count);
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
