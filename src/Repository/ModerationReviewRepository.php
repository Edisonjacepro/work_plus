<?php

namespace App\Repository;

use App\Entity\ModerationReview;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModerationReview>
 */
class ModerationReviewRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModerationReview::class);
    }

    /**
     * @return list<ModerationReview>
     */
    public function findLatestForOffer(int $offerId, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.offer = :offerId')
            ->setParameter('offerId', $offerId)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
