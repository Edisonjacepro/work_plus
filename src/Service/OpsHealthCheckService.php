<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

class OpsHealthCheckService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LockFactory $lockFactory,
        private readonly string $mailerDsn,
        private readonly string $applicationAttachmentDir,
        private readonly string $pointsClaimUploadDir,
        private readonly string $gdprExportDir,
    ) {
    }

    /**
     * @return array{
     *     checkedAt: string,
     *     hasFailures: bool,
     *     failuresCount: int,
     *     warningsCount: int,
     *     checks: list<array{
     *         key: string,
     *         status: string,
     *         message: string
     *     }>
     * }
     */
    public function run(): array
    {
        $checks = [];
        $checks[] = $this->checkDatabase();
        $checks[] = $this->checkLockBackend();
        $checks[] = $this->checkMailer();
        $checks[] = $this->checkDirectory('application_attachments_dir', $this->applicationAttachmentDir);
        $checks[] = $this->checkDirectory('points_claim_upload_dir', $this->pointsClaimUploadDir);
        $checks[] = $this->checkDirectory('gdpr_export_dir', $this->gdprExportDir);

        $failuresCount = 0;
        $warningsCount = 0;
        foreach ($checks as $check) {
            if ('fail' === $check['status']) {
                ++$failuresCount;
            }
            if ('warn' === $check['status']) {
                ++$warningsCount;
            }
        }

        return [
            'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'hasFailures' => $failuresCount > 0,
            'failuresCount' => $failuresCount,
            'warningsCount' => $warningsCount,
            'checks' => $checks,
        ];
    }

    /**
     * @return array{key: string, status: string, message: string}
     */
    private function checkDatabase(): array
    {
        try {
            $result = $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable $exception) {
            return [
                'key' => 'database',
                'status' => 'fail',
                'message' => sprintf('database query failed: %s', $exception->getMessage()),
            ];
        }

        if ('1' !== (string) $result) {
            return [
                'key' => 'database',
                'status' => 'fail',
                'message' => 'database health query returned unexpected result',
            ];
        }

        return [
            'key' => 'database',
            'status' => 'ok',
            'message' => 'database reachable',
        ];
    }

    /**
     * @return array{key: string, status: string, message: string}
     */
    private function checkLockBackend(): array
    {
        try {
            $resource = 'ops_health_check_probe_' . bin2hex(random_bytes(6));
            $lock = $this->lockFactory->createLock($resource, 5.0, true);
            $acquired = $lock->acquire(false);
            if (true !== $acquired) {
                return [
                    'key' => 'lock_backend',
                    'status' => 'fail',
                    'message' => 'unable to acquire lock',
                ];
            }
            $lock->release();
        } catch (\Throwable $exception) {
            return [
                'key' => 'lock_backend',
                'status' => 'fail',
                'message' => sprintf('lock backend error: %s', $exception->getMessage()),
            ];
        }

        return [
            'key' => 'lock_backend',
            'status' => 'ok',
            'message' => 'lock backend operational',
        ];
    }

    /**
     * @return array{key: string, status: string, message: string}
     */
    private function checkMailer(): array
    {
        $normalizedDsn = strtolower(trim($this->mailerDsn));
        if ('' === $normalizedDsn) {
            return [
                'key' => 'mailer',
                'status' => 'warn',
                'message' => 'mailer DSN is empty',
            ];
        }

        if (str_starts_with($normalizedDsn, 'null://')) {
            return [
                'key' => 'mailer',
                'status' => 'warn',
                'message' => 'mailer DSN uses null transport',
            ];
        }

        return [
            'key' => 'mailer',
            'status' => 'ok',
            'message' => 'mailer DSN configured',
        ];
    }

    /**
     * @return array{key: string, status: string, message: string}
     */
    private function checkDirectory(string $key, string $path): array
    {
        if (is_dir($path)) {
            if (is_writable($path)) {
                return [
                    'key' => $key,
                    'status' => 'ok',
                    'message' => sprintf('directory exists and is writable: %s', $path),
                ];
            }

            return [
                'key' => $key,
                'status' => 'fail',
                'message' => sprintf('directory is not writable: %s', $path),
            ];
        }

        $existingParent = $this->findNearestExistingParent($path);
        if (null !== $existingParent && is_writable($existingParent)) {
            return [
                'key' => $key,
                'status' => 'warn',
                'message' => sprintf('directory missing but nearest parent is writable: %s', $path),
            ];
        }

        return [
            'key' => $key,
            'status' => 'fail',
            'message' => sprintf('directory missing and parent is not writable: %s', $path),
        ];
    }

    private function findNearestExistingParent(string $path): ?string
    {
        $cursor = rtrim($path, '/\\');
        if ('' === $cursor) {
            return null;
        }

        while (true) {
            $parent = dirname($cursor);
            if ($parent === $cursor) {
                return is_dir($cursor) ? $cursor : null;
            }

            if ('' === $parent || '.' === $parent) {
                return null;
            }

            if (is_dir($parent)) {
                return $parent;
            }

            $cursor = $parent;
        }
    }
}
