<?php

namespace App\Repository;

use App\Entity\Offer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Offer>
 */
class OfferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Offer::class);
    }

    /**
     * @return Offer[]
     */
    public function findVisible(): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.isVisible = :visible')
            ->setParameter('visible', true)
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
