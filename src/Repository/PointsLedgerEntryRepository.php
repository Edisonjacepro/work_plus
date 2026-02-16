<?php

namespace App\Repository;

use App\Entity\PointsLedgerEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PointsLedgerEntry>
 */
class PointsLedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PointsLedgerEntry::class);
    }

    public function existsByIdempotencyKey(string $idempotencyKey): bool
    {
        $count = (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.idempotencyKey = :key')
            ->setParameter('key', $idempotencyKey)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function getCompanyBalance(int $companyId): int
    {
        $balance = $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.points), 0)')
            ->andWhere('l.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $balance;
    }
}
