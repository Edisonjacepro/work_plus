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

    public function sumCompanyCreditPointsSince(int $companyId, \DateTimeImmutable $since): int
    {
        $sum = $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.points), 0)')
            ->andWhere('l.company = :companyId')
            ->andWhere('l.entryType = :entryType')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('companyId', $companyId)
            ->setParameter('entryType', PointsLedgerEntry::TYPE_CREDIT)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $sum;
    }

    public function countCompanyCreditEntriesSince(int $companyId, \DateTimeImmutable $since): int
    {
        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.company = :companyId')
            ->andWhere('l.entryType = :entryType')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('companyId', $companyId)
            ->setParameter('entryType', PointsLedgerEntry::TYPE_CREDIT)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function countCompanyCreditEntriesByReferenceSince(int $companyId, string $referenceType, \DateTimeImmutable $since): int
    {
        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.company = :companyId')
            ->andWhere('l.entryType = :entryType')
            ->andWhere('l.referenceType = :referenceType')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('companyId', $companyId)
            ->setParameter('entryType', PointsLedgerEntry::TYPE_CREDIT)
            ->setParameter('referenceType', $referenceType)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function sumUserCreditPointsSince(int $userId, \DateTimeImmutable $since): int
    {
        $sum = $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.points), 0)')
            ->andWhere('l.user = :userId')
            ->andWhere('l.entryType = :entryType')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('userId', $userId)
            ->setParameter('entryType', PointsLedgerEntry::TYPE_CREDIT)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $sum;
    }

    public function countUserCreditEntriesSince(int $userId, \DateTimeImmutable $since): int
    {
        $count = $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.user = :userId')
            ->andWhere('l.entryType = :entryType')
            ->andWhere('l.createdAt >= :since')
            ->setParameter('userId', $userId)
            ->setParameter('entryType', PointsLedgerEntry::TYPE_CREDIT)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
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

    /**
     * @return list<PointsLedgerEntry>
     */
    public function findLatestForCompany(int $companyId, int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.company = :companyId')
            ->setParameter('companyId', $companyId)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function getUserBalance(int $userId): int
    {
        $balance = $this->createQueryBuilder('l')
            ->select('COALESCE(SUM(l.points), 0)')
            ->andWhere('l.user = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $balance;
    }

    /**
     * @return list<PointsLedgerEntry>
     */
    public function findLatestForUser(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('l.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
