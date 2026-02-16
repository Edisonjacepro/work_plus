<?php

namespace App\Repository;

use App\Entity\ImpactScore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImpactScore>
 */
class ImpactScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImpactScore::class);
    }

    public function findLatestForOffer(int $offerId): ?ImpactScore
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.offer = :offerId')
            ->setParameter('offerId', $offerId)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
