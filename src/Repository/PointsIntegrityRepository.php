<?php

namespace App\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

class PointsIntegrityRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function countApprovedClaimsWithoutCredit(): int
    {
        $sql = <<<'SQL'
SELECT COUNT(pc.id)
FROM points_claim pc
WHERE pc.status = 'APPROVED'
  AND NOT EXISTS (
      SELECT 1
      FROM points_ledger_entry ple
      WHERE ple.reference_type = 'POINTS_CLAIM_APPROVAL'
        AND ple.entry_type = 'CREDIT'
        AND ple.reference_id = pc.id
  )
SQL;

        return (int) $this->connection->fetchOne($sql);
    }

    /**
     * @return list<array{claimId: int, companyId: int, approvedPoints: int|null}>
     */
    public function findApprovedClaimsWithoutCredit(int $limit): array
    {
        $sql = <<<'SQL'
SELECT
    pc.id AS "claimId",
    pc.company_id AS "companyId",
    pc.approved_points AS "approvedPoints"
FROM points_claim pc
WHERE pc.status = 'APPROVED'
  AND NOT EXISTS (
      SELECT 1
      FROM points_ledger_entry ple
      WHERE ple.reference_type = 'POINTS_CLAIM_APPROVAL'
        AND ple.entry_type = 'CREDIT'
        AND ple.reference_id = pc.id
  )
ORDER BY pc.id DESC
LIMIT :limit
SQL;

        return $this->connection->fetchAllAssociative($sql, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);
    }

    public function countLedgerCreditsWithoutClaim(): int
    {
        $sql = <<<'SQL'
SELECT COUNT(ple.id)
FROM points_ledger_entry ple
LEFT JOIN points_claim pc ON pc.id = ple.reference_id
WHERE ple.reference_type = 'POINTS_CLAIM_APPROVAL'
  AND ple.entry_type = 'CREDIT'
  AND ple.reference_id IS NOT NULL
  AND pc.id IS NULL
SQL;

        return (int) $this->connection->fetchOne($sql);
    }

    /**
     * @return list<array{ledgerEntryId: int, referenceId: int, companyId: int|null, points: int}>
     */
    public function findLedgerCreditsWithoutClaim(int $limit): array
    {
        $sql = <<<'SQL'
SELECT
    ple.id AS "ledgerEntryId",
    ple.reference_id AS "referenceId",
    ple.company_id AS "companyId",
    ple.points AS "points"
FROM points_ledger_entry ple
LEFT JOIN points_claim pc ON pc.id = ple.reference_id
WHERE ple.reference_type = 'POINTS_CLAIM_APPROVAL'
  AND ple.entry_type = 'CREDIT'
  AND ple.reference_id IS NOT NULL
  AND pc.id IS NULL
ORDER BY ple.id DESC
LIMIT :limit
SQL;

        return $this->connection->fetchAllAssociative($sql, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);
    }

    public function countApprovedClaimsPointsMismatch(): int
    {
        $sql = <<<'SQL'
SELECT COUNT(*)
FROM (
    SELECT
        pc.id
    FROM points_claim pc
    INNER JOIN points_ledger_entry ple
        ON ple.reference_type = 'POINTS_CLAIM_APPROVAL'
        AND ple.entry_type = 'CREDIT'
        AND ple.reference_id = pc.id
    WHERE pc.status = 'APPROVED'
    GROUP BY pc.id, pc.company_id, pc.approved_points
    HAVING pc.approved_points IS NULL OR COALESCE(SUM(ple.points), 0) <> pc.approved_points
) anomalies
SQL;

        return (int) $this->connection->fetchOne($sql);
    }

    /**
     * @return list<array{claimId: int, companyId: int, approvedPoints: int|null, creditedPoints: int}>
     */
    public function findApprovedClaimsPointsMismatch(int $limit): array
    {
        $sql = <<<'SQL'
SELECT
    pc.id AS "claimId",
    pc.company_id AS "companyId",
    pc.approved_points AS "approvedPoints",
    COALESCE(SUM(ple.points), 0) AS "creditedPoints"
FROM points_claim pc
INNER JOIN points_ledger_entry ple
    ON ple.reference_type = 'POINTS_CLAIM_APPROVAL'
    AND ple.entry_type = 'CREDIT'
    AND ple.reference_id = pc.id
WHERE pc.status = 'APPROVED'
GROUP BY pc.id, pc.company_id, pc.approved_points
HAVING pc.approved_points IS NULL OR COALESCE(SUM(ple.points), 0) <> pc.approved_points
ORDER BY pc.id DESC
LIMIT :limit
SQL;

        return $this->connection->fetchAllAssociative($sql, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);
    }

    public function countDuplicateClaimApprovalCredits(): int
    {
        $sql = <<<'SQL'
SELECT COUNT(*)
FROM (
    SELECT ple.reference_id
    FROM points_ledger_entry ple
    WHERE ple.reference_type = 'POINTS_CLAIM_APPROVAL'
      AND ple.entry_type = 'CREDIT'
      AND ple.reference_id IS NOT NULL
    GROUP BY ple.reference_id
    HAVING COUNT(*) > 1
) duplicates
SQL;

        return (int) $this->connection->fetchOne($sql);
    }

    /**
     * @return list<array{claimId: int, creditsCount: int, creditedPoints: int}>
     */
    public function findDuplicateClaimApprovalCredits(int $limit): array
    {
        $sql = <<<'SQL'
SELECT
    ple.reference_id AS "claimId",
    COUNT(*) AS "creditsCount",
    COALESCE(SUM(ple.points), 0) AS "creditedPoints"
FROM points_ledger_entry ple
WHERE ple.reference_type = 'POINTS_CLAIM_APPROVAL'
  AND ple.entry_type = 'CREDIT'
  AND ple.reference_id IS NOT NULL
GROUP BY ple.reference_id
HAVING COUNT(*) > 1
ORDER BY ple.reference_id DESC
LIMIT :limit
SQL;

        return $this->connection->fetchAllAssociative($sql, ['limit' => $limit], ['limit' => ParameterType::INTEGER]);
    }
}
