<?php

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;

class GdprDataExportService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{
     *     generatedAt: string,
     *     schemaVersion: string,
     *     subject: array{type: string, id: int},
     *     data: array<string, mixed>
     * }
     */
    public function exportUser(int $userId): array
    {
        $user = $this->connection->fetchAssociative(
            'SELECT id, email, account_type, first_name, last_name, roles, company_id, created_at, updated_at FROM "user" WHERE id = :id',
            ['id' => $userId],
            ['id' => ParameterType::INTEGER],
        );
        if (!is_array($user)) {
            throw new \RuntimeException('User not found.');
        }

        $report = [
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'schemaVersion' => 'gdpr_export_v1',
            'subject' => [
                'type' => 'USER',
                'id' => $userId,
            ],
            'data' => [
                'user' => $this->normalizeJsonColumns($user, ['roles']),
                'offersAuthored' => $this->connection->fetchAllAssociative(
                    'SELECT id, title, status, moderation_status, company_id, created_at, published_at, is_visible FROM offer WHERE author_id = :userId ORDER BY id DESC',
                    ['userId' => $userId],
                    ['userId' => ParameterType::INTEGER],
                ),
                'applicationsAsCandidate' => $this->connection->fetchAllAssociative(
                    'SELECT id, offer_id, status, email, first_name, last_name, message, cv_file_path, created_at, hired_at FROM application WHERE candidate_id = :userId ORDER BY id DESC',
                    ['userId' => $userId],
                    ['userId' => ParameterType::INTEGER],
                ),
                'applicationMessagesAuthored' => $this->connection->fetchAllAssociative(
                    'SELECT id, application_id, author_type, body, created_at FROM application_message WHERE author_id = :userId ORDER BY id DESC',
                    ['userId' => $userId],
                    ['userId' => ParameterType::INTEGER],
                ),
                'pointsLedgerEntries' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, entry_type, points, reason, reference_type, reference_id, metadata, created_at FROM points_ledger_entry WHERE user_id = :userId ORDER BY id DESC',
                        ['userId' => $userId],
                        ['userId' => ParameterType::INTEGER],
                    ),
                    ['metadata'],
                ),
                'pointsClaimsReviewedByUser' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, company_id, claim_type, status, requested_points, approved_points, decision_reason_code, evidence_documents, external_checks, created_at, reviewed_at FROM points_claim WHERE reviewed_by_id = :userId ORDER BY id DESC',
                        ['userId' => $userId],
                        ['userId' => ParameterType::INTEGER],
                    ),
                    ['evidence_documents', 'external_checks'],
                ),
            ],
        ];

        $this->logger->info('GDPR export generated.', [
            'subjectType' => 'USER',
            'subjectId' => $userId,
            'schemaVersion' => $report['schemaVersion'],
        ]);

        return $report;
    }

    /**
     * @return array{
     *     generatedAt: string,
     *     schemaVersion: string,
     *     subject: array{type: string, id: int},
     *     data: array<string, mixed>
     * }
     */
    public function exportCompany(int $companyId): array
    {
        $company = $this->connection->fetchAssociative(
            'SELECT id, name, description, website, city, sector, company_size, recruiter_plan_code, recruiter_plan_started_at, recruiter_plan_expires_at, created_at, updated_at FROM company WHERE id = :id',
            ['id' => $companyId],
            ['id' => ParameterType::INTEGER],
        );
        if (!is_array($company)) {
            throw new \RuntimeException('Company not found.');
        }

        $report = [
            'generatedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'schemaVersion' => 'gdpr_export_v1',
            'subject' => [
                'type' => 'COMPANY',
                'id' => $companyId,
            ],
            'data' => [
                'company' => $company,
                'users' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, email, account_type, first_name, last_name, roles, created_at, updated_at FROM "user" WHERE company_id = :companyId ORDER BY id DESC',
                        ['companyId' => $companyId],
                        ['companyId' => ParameterType::INTEGER],
                    ),
                    ['roles'],
                ),
                'offers' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, title, description, status, impact_categories, moderation_status, moderation_reason_code, moderation_reason, moderation_score, moderation_rule_version, is_visible, created_at, published_at, moderated_at FROM offer WHERE company_id = :companyId ORDER BY id DESC',
                        ['companyId' => $companyId],
                        ['companyId' => ParameterType::INTEGER],
                    ),
                    ['impact_categories'],
                ),
                'pointsClaims' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, claim_type, status, requested_points, approved_points, decision_reason_code, decision_reason, evidence_documents, external_checks, evidence_score, evidence_issued_at, created_at, reviewed_at, reviewed_by_id FROM points_claim WHERE company_id = :companyId ORDER BY id DESC',
                        ['companyId' => $companyId],
                        ['companyId' => ParameterType::INTEGER],
                    ),
                    ['evidence_documents', 'external_checks'],
                ),
                'pointsLedgerEntries' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, entry_type, points, reason, reference_type, reference_id, rule_version, idempotency_key, metadata, created_at, user_id FROM points_ledger_entry WHERE company_id = :companyId ORDER BY id DESC',
                        ['companyId' => $companyId],
                        ['companyId' => ParameterType::INTEGER],
                    ),
                    ['metadata'],
                ),
                'pointsPolicyDecisions' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, decision_status, reason_code, reason_text, reference_type, reference_id, points, rule_version, metadata, created_at FROM points_policy_decision WHERE company_id = :companyId ORDER BY id DESC',
                        ['companyId' => $companyId],
                        ['companyId' => ParameterType::INTEGER],
                    ),
                    ['metadata'],
                ),
                'subscriptionPayments' => $this->normalizeRowsJsonColumns(
                    $this->connection->fetchAllAssociative(
                        'SELECT id, initiated_by_id, plan_code, amount_cents, currency_code, provider, status, idempotency_key, provider_payload, paid_at, period_start, period_end, created_at FROM recruiter_subscription_payment WHERE company_id = :companyId ORDER BY id DESC',
                        ['companyId' => $companyId],
                        ['companyId' => ParameterType::INTEGER],
                    ),
                    ['provider_payload'],
                ),
            ],
        ];

        $this->logger->info('GDPR export generated.', [
            'subjectType' => 'COMPANY',
            'subjectId' => $companyId,
            'schemaVersion' => $report['schemaVersion'],
        ]);

        return $report;
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $jsonColumns
     * @return array<string, mixed>
     */
    private function normalizeJsonColumns(array $row, array $jsonColumns): array
    {
        foreach ($jsonColumns as $jsonColumn) {
            $value = $row[$jsonColumn] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (JSON_ERROR_NONE === json_last_error()) {
                $row[$jsonColumn] = $decoded;
            }
        }

        return $row;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $jsonColumns
     * @return list<array<string, mixed>>
     */
    private function normalizeRowsJsonColumns(array $rows, array $jsonColumns): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = $this->normalizeJsonColumns($row, $jsonColumns);
        }

        return $normalized;
    }
}
