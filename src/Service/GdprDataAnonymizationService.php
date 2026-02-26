<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Types\Types;
use Psr\Log\LoggerInterface;

class GdprDataAnonymizationService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     subjectType: string,
     *     subjectId: int,
     *     dryRun: bool,
     *     summary: array<string, int>
     * }
     */
    public function anonymizeUser(int $userId, bool $dryRun = false): array
    {
        $user = $this->connection->fetchAssociative(
            'SELECT id FROM "user" WHERE id = :id',
            ['id' => $userId],
            ['id' => ParameterType::INTEGER],
        );
        if (!is_array($user)) {
            throw new \RuntimeException('User not found.');
        }

        $summary = [
            'userRows' => 0,
            'applicationsRows' => 0,
            'applicationMessagesRows' => 0,
            'pointsClaimsReviewedRows' => 0,
            'pointsClaimReviewEventsRows' => 0,
            'moderationReviewRows' => 0,
            'subscriptionPaymentsRows' => 0,
            'pointsLedgerRows' => 0,
        ];

        if ($dryRun) {
            $summary['applicationsRows'] = $this->countRows('SELECT COUNT(id) FROM application WHERE candidate_id = :id', $userId);
            $summary['applicationMessagesRows'] = $this->countRows('SELECT COUNT(id) FROM application_message WHERE author_id = :id', $userId);
            $summary['pointsClaimsReviewedRows'] = $this->countRows('SELECT COUNT(id) FROM points_claim WHERE reviewed_by_id = :id', $userId);
            $summary['pointsClaimReviewEventsRows'] = $this->countRows('SELECT COUNT(id) FROM points_claim_review_event WHERE actor_id = :id', $userId);
            $summary['moderationReviewRows'] = $this->countRows('SELECT COUNT(id) FROM moderation_review WHERE actor_id = :id', $userId);
            $summary['subscriptionPaymentsRows'] = $this->countRows('SELECT COUNT(id) FROM recruiter_subscription_payment WHERE initiated_by_id = :id', $userId);
            $summary['pointsLedgerRows'] = $this->countRows('SELECT COUNT(id) FROM points_ledger_entry WHERE user_id = :id', $userId);
            $summary['userRows'] = 1;

            $this->logger->info('GDPR anonymization dry-run completed.', [
                'subjectType' => 'USER',
                'subjectId' => $userId,
                'summary' => $summary,
            ]);

            return [
                'subjectType' => 'USER',
                'subjectId' => $userId,
                'dryRun' => true,
                'summary' => $summary,
            ];
        }

        $this->connection->beginTransaction();

        try {
            $summary['applicationsRows'] = $this->connection->executeStatement(
                'UPDATE application SET candidate_id = NULL, email = :email, first_name = :firstName, last_name = :lastName, message = :message, cv_file_path = NULL WHERE candidate_id = :id',
                [
                    'email' => sprintf('anonymized_candidate_%d@anonymized.local', $userId),
                    'firstName' => 'ANONYMIZED',
                    'lastName' => 'ANONYMIZED',
                    'message' => 'ANONYMIZED',
                    'id' => $userId,
                ],
                [
                    'email' => Types::STRING,
                    'firstName' => Types::STRING,
                    'lastName' => Types::STRING,
                    'message' => Types::STRING,
                    'id' => Types::INTEGER,
                ],
            );
            $summary['applicationMessagesRows'] = $this->connection->executeStatement(
                'UPDATE application_message SET author_id = NULL, body = :body WHERE author_id = :id',
                [
                    'body' => 'ANONYMIZED',
                    'id' => $userId,
                ],
                [
                    'body' => Types::STRING,
                    'id' => Types::INTEGER,
                ],
            );
            $summary['pointsClaimsReviewedRows'] = $this->connection->executeStatement(
                'UPDATE points_claim SET reviewed_by_id = NULL WHERE reviewed_by_id = :id',
                ['id' => $userId],
                ['id' => Types::INTEGER],
            );
            $summary['pointsClaimReviewEventsRows'] = $this->connection->executeStatement(
                'UPDATE points_claim_review_event SET actor_id = NULL WHERE actor_id = :id',
                ['id' => $userId],
                ['id' => Types::INTEGER],
            );
            $summary['moderationReviewRows'] = $this->connection->executeStatement(
                'UPDATE moderation_review SET actor_id = NULL WHERE actor_id = :id',
                ['id' => $userId],
                ['id' => Types::INTEGER],
            );
            $summary['subscriptionPaymentsRows'] = $this->connection->executeStatement(
                'UPDATE recruiter_subscription_payment SET initiated_by_id = NULL WHERE initiated_by_id = :id',
                ['id' => $userId],
                ['id' => Types::INTEGER],
            );
            $summary['pointsLedgerRows'] = $this->connection->executeStatement(
                'UPDATE points_ledger_entry SET user_id = NULL WHERE user_id = :id',
                ['id' => $userId],
                ['id' => Types::INTEGER],
            );
            $summary['userRows'] = $this->connection->executeStatement(
                'UPDATE "user" SET email = :email, first_name = NULL, last_name = NULL WHERE id = :id',
                [
                    'email' => sprintf('anonymized_user_%d@anonymized.local', $userId),
                    'id' => $userId,
                ],
                [
                    'email' => Types::STRING,
                    'id' => Types::INTEGER,
                ],
            );

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        $this->logger->warning('GDPR anonymization executed.', [
            'subjectType' => 'USER',
            'subjectId' => $userId,
            'summary' => $summary,
        ]);

        return [
            'subjectType' => 'USER',
            'subjectId' => $userId,
            'dryRun' => false,
            'summary' => $summary,
        ];
    }

    /**
     * @return array{
     *     subjectType: string,
     *     subjectId: int,
     *     dryRun: bool,
     *     summary: array<string, int>
     * }
     */
    public function anonymizeCompany(int $companyId, bool $dryRun = false): array
    {
        $company = $this->connection->fetchAssociative(
            'SELECT id FROM company WHERE id = :id',
            ['id' => $companyId],
            ['id' => ParameterType::INTEGER],
        );
        if (!is_array($company)) {
            throw new \RuntimeException('Company not found.');
        }

        /** @var list<int|string> $userIds */
        $userIds = $this->connection->fetchFirstColumn(
            'SELECT id FROM "user" WHERE company_id = :companyId ORDER BY id ASC',
            ['companyId' => $companyId],
            ['companyId' => Types::INTEGER],
        );

        $summary = [
            'companyRows' => 0,
            'offersRows' => 0,
            'companyUsers' => count($userIds),
            'companyUserRows' => 0,
        ];

        if ($dryRun) {
            $summary['companyRows'] = 1;
            $summary['offersRows'] = $this->countRows('SELECT COUNT(id) FROM offer WHERE company_id = :id', $companyId);
            $summary['companyUserRows'] = $this->countRows('SELECT COUNT(id) FROM "user" WHERE company_id = :id', $companyId);

            $this->logger->info('GDPR anonymization dry-run completed.', [
                'subjectType' => 'COMPANY',
                'subjectId' => $companyId,
                'summary' => $summary,
            ]);

            return [
                'subjectType' => 'COMPANY',
                'subjectId' => $companyId,
                'dryRun' => true,
                'summary' => $summary,
            ];
        }

        $this->connection->beginTransaction();

        try {
            $summary['offersRows'] = $this->connection->executeStatement(
                'UPDATE offer SET is_visible = FALSE, status = :status WHERE company_id = :companyId',
                [
                    'status' => 'DRAFT',
                    'companyId' => $companyId,
                ],
                [
                    'status' => Types::STRING,
                    'companyId' => Types::INTEGER,
                ],
            );

            foreach ($userIds as $userIdValue) {
                $userId = (int) $userIdValue;
                $summary['companyUserRows'] += $this->connection->executeStatement(
                    'UPDATE "user" SET email = :email, first_name = NULL, last_name = NULL WHERE id = :id',
                    [
                        'email' => sprintf('anonymized_company_user_%d@anonymized.local', $userId),
                        'id' => $userId,
                    ],
                    [
                        'email' => Types::STRING,
                        'id' => Types::INTEGER,
                    ],
                );
            }

            $summary['companyRows'] = $this->connection->executeStatement(
                'UPDATE company SET name = :name, description = NULL, website = NULL, city = NULL, sector = NULL, company_size = NULL, recruiter_plan_code = :planCode, recruiter_plan_started_at = NULL, recruiter_plan_expires_at = NULL WHERE id = :id',
                [
                    'name' => sprintf('ANONYMIZED_COMPANY_%d', $companyId),
                    'planCode' => 'STARTER',
                    'id' => $companyId,
                ],
                [
                    'name' => Types::STRING,
                    'planCode' => Types::STRING,
                    'id' => Types::INTEGER,
                ],
            );

            $this->connection->commit();
        } catch (\Throwable $exception) {
            $this->connection->rollBack();
            throw $exception;
        }

        $this->logger->warning('GDPR anonymization executed.', [
            'subjectType' => 'COMPANY',
            'subjectId' => $companyId,
            'summary' => $summary,
        ]);

        return [
            'subjectType' => 'COMPANY',
            'subjectId' => $companyId,
            'dryRun' => false,
            'summary' => $summary,
        ];
    }

    private function countRows(string $sql, int $id): int
    {
        return (int) $this->connection->fetchOne($sql, ['id' => $id], ['id' => Types::INTEGER]);
    }
}
