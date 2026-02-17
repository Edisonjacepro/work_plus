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

    /**
     * @return list<PointsClaim>
     */
    public function findPendingForReview(int $limit = 50): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status IN (:statuses)')
            ->setParameter('statuses', [PointsClaim::STATUS_SUBMITTED, PointsClaim::STATUS_IN_REVIEW])
            ->orderBy('c.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
