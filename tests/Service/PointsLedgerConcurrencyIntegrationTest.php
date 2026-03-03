<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\PointsLedgerEntry;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PointsLedgerConcurrencyIntegrationTest extends KernelTestCase
{
    public function testClaimApprovalCreditRemainsSingleUnderStalePreCheck(): void
    {
        $entityManager = $this->requireDatabaseOrSkip();
        $connection = $entityManager->getConnection();

        if (!$connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            self::markTestSkipped('Ce test de concurrence necessite PostgreSQL.');
        }

        $suffix = bin2hex(random_bytes(4));
        $company = (new Company())
            ->setName('Concurrency Company ' . $suffix)
            ->setDescription('Entreprise de test concurrence');
        $claim = (new PointsClaim())
            ->setCompany($company)
            ->setClaimType(PointsClaim::CLAIM_TYPE_TRAINING)
            ->setStatus(PointsClaim::STATUS_APPROVED)
            ->setRequestedPoints(20)
            ->setApprovedPoints(20)
            ->setEvidenceScore(90)
            ->setEvidenceDocuments([['valid' => true, 'fileHash' => 'hash-' . $suffix]])
            ->setExternalChecks(['coherenceOk' => true])
            ->setRuleVersion(PointsClaim::RULE_VERSION_V1)
            ->setIdempotencyKey('claim-concurrency-' . $suffix);

        $entityManager->persist($company);
        $entityManager->persist($claim);
        $entityManager->flush();

        $companyId = $company->getId();
        $claimId = $claim->getId();
        self::assertNotNull($companyId);
        self::assertNotNull($claimId);

        $params = $connection->getParams();
        unset($params['wrapperClass']);

        $connectionA = DriverManager::getConnection($params);
        $connectionB = DriverManager::getConnection($params);

        $idempotencyKeyA = sprintf('points_claim_approval_%s_a', $claim->getIdempotencyKey());
        $idempotencyKeyB = sprintf('points_claim_approval_%s_b', $claim->getIdempotencyKey());

        $preCheckSql = 'SELECT COUNT(id) FROM points_ledger_entry WHERE idempotency_key = :idempotencyKey';
        $insertSql = <<<'SQL'
            INSERT INTO points_ledger_entry (
                company_id,
                user_id,
                entry_type,
                points,
                reason,
                reference_type,
                reference_id,
                rule_version,
                idempotency_key,
                metadata,
                created_at
            ) VALUES (
                :companyId,
                NULL,
                :entryType,
                :points,
                :reason,
                :referenceType,
                :referenceId,
                :ruleVersion,
                :idempotencyKey,
                :metadata,
                CURRENT_TIMESTAMP
            )
            ON CONFLICT DO NOTHING
            SQL;

        try {
            $seenA = (int) $connectionA->fetchOne($preCheckSql, ['idempotencyKey' => $idempotencyKeyA], ['idempotencyKey' => ParameterType::STRING]);
            $seenB = (int) $connectionB->fetchOne($preCheckSql, ['idempotencyKey' => $idempotencyKeyB], ['idempotencyKey' => ParameterType::STRING]);
            self::assertSame(0, $seenA);
            self::assertSame(0, $seenB);

            $paramsA = [
                'companyId' => $companyId,
                'entryType' => PointsLedgerEntry::TYPE_CREDIT,
                'points' => 20,
                'reason' => 'Concurrency guard test A',
                'referenceType' => PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                'referenceId' => $claimId,
                'ruleVersion' => PointsClaim::RULE_VERSION_V1,
                'idempotencyKey' => $idempotencyKeyA,
                'metadata' => [
                    'claimId' => $claimId,
                    'source' => 'concurrency_test_a',
                ],
            ];
            $paramsB = $paramsA;
            $paramsB['reason'] = 'Concurrency guard test B';
            $paramsB['idempotencyKey'] = $idempotencyKeyB;
            $paramsB['metadata'] = [
                'claimId' => $claimId,
                'source' => 'concurrency_test_b',
            ];
            $types = [
                'companyId' => Types::INTEGER,
                'entryType' => Types::STRING,
                'points' => Types::INTEGER,
                'reason' => Types::STRING,
                'referenceType' => Types::STRING,
                'referenceId' => Types::INTEGER,
                'ruleVersion' => Types::STRING,
                'idempotencyKey' => Types::STRING,
                'metadata' => Types::JSON,
            ];

            $affectedRowsA = $connectionA->executeStatement($insertSql, $paramsA, $types);
            $affectedRowsB = $connectionB->executeStatement($insertSql, $paramsB, $types);

            self::assertSame(1, $affectedRowsA);
            self::assertSame(0, $affectedRowsB);

            $ledgerEntriesForClaim = (int) $connection->fetchOne(
                "SELECT COUNT(id) FROM points_ledger_entry WHERE reference_type = 'POINTS_CLAIM_APPROVAL' AND entry_type = 'CREDIT' AND reference_id = :claimId",
                ['claimId' => $claimId],
                ['claimId' => Types::INTEGER],
            );
            self::assertSame(1, $ledgerEntriesForClaim);
        } finally {
            $connectionA->close();
            $connectionB->close();

            if (null !== $claimId) {
                $connection->executeStatement(
                    "DELETE FROM points_ledger_entry WHERE reference_type = 'POINTS_CLAIM_APPROVAL' AND reference_id = :claimId",
                    ['claimId' => $claimId],
                    ['claimId' => Types::INTEGER],
                );
                $connection->executeStatement(
                    'DELETE FROM points_claim WHERE id = :claimId',
                    ['claimId' => $claimId],
                    ['claimId' => Types::INTEGER],
                );
            }

            if (null !== $companyId) {
                $connection->executeStatement(
                    'DELETE FROM company WHERE id = :companyId',
                    ['companyId' => $companyId],
                    ['companyId' => Types::INTEGER],
                );
            }
        }
    }

    private function requireDatabaseOrSkip(): EntityManagerInterface
    {
        self::bootKernel();

        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Connexion base de test indisponible pour ce test de concurrence.');
        }

        return $entityManager;
    }
}
