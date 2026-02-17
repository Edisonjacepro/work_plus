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
