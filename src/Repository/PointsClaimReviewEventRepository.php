<?php

namespace App\Repository;

use App\Entity\PointsClaimReviewEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PointsClaimReviewEvent>
 */
class PointsClaimReviewEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PointsClaimReviewEvent::class);
    }

    /**
     * @return list<PointsClaimReviewEvent>
     */
    public function findLatestForClaim(int $claimId, int $limit = 50): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.pointsClaim = :claimId')
            ->setParameter('claimId', $claimId)
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
