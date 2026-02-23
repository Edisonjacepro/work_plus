<?php

namespace App\Repository;

use App\Entity\PointsClaim;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PointsClaim>
 */
class PointsClaimRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PointsClaim::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?PointsClaim
    {
        return $this->findOneBy(['idempotencyKey' => $idempotencyKey]);
    }

    public function sumApprovedPointsSince(int $companyId, \DateTimeImmutable $since): int
    {
        $total = $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.approvedPoints), 0)')
            ->andWhere('c.company = :companyId')
            ->andWhere('c.status = :status')
            ->andWhere('(c.reviewedAt >= :since OR (c.reviewedAt IS NULL AND c.createdAt >= :since))')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', PointsClaim::STATUS_APPROVED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }

    public function countApprovedClaimsSince(int $companyId, \DateTimeImmutable $since): int
    {
        $count = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.company = :companyId')
            ->andWhere('c.status = :status')
            ->andWhere('(c.reviewedAt >= :since OR (c.reviewedAt IS NULL AND c.createdAt >= :since))')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', PointsClaim::STATUS_APPROVED)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function hasEvidenceHashForCompany(int $companyId, string $fileHash): bool
    {
        $sql = <<<'SQL'
SELECT COUNT(id)
FROM points_claim
WHERE company_id = :companyId
  AND CAST(evidence_documents AS TEXT) LIKE :hashPattern
SQL;

        $count = (int) $this->getEntityManager()->getConnection()->fetchOne($sql, [
            'companyId' => $companyId,
            'hashPattern' => '%"fileHash":"' . $fileHash . '"%',
        ]);

        return $count > 0;
    }

    /**
     * @return list<PointsClaim>
     */
    public function findLatestForCompany(int $companyId, int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('c.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

}
