<?php

namespace App\Repository;

use App\Entity\PointsPolicyDecision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PointsPolicyDecision>
 */
class PointsPolicyDecisionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PointsPolicyDecision::class);
    }

    /**
     * @return list<PointsPolicyDecision>
     */
    public function findLatestForCompany(int $companyId, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<PointsPolicyDecision>
     */
    public function findLatestForUser(int $userId, int $limit = 50): array
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
