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
    public function findPageForCompany(
        int $companyId,
        int $page = 1,
        int $limit = 20,
        ?string $decisionStatus = null,
        ?string $referenceType = null,
    ): array {
        $offset = max(0, ($page - 1) * $limit);

        return $this->buildCompanyFilterQuery($companyId, $decisionStatus, $referenceType)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForCompanyFilters(
        int $companyId,
        ?string $decisionStatus = null,
        ?string $referenceType = null,
    ): int {
        $count = $this->buildCompanyFilterQuery($companyId, $decisionStatus, $referenceType)
            ->select('COUNT(d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function countBlockedForCompanySince(int $companyId, \DateTimeImmutable $since): int
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->andWhere('d.company = :companyId')
            ->andWhere('d.decisionStatus = :status')
            ->andWhere('d.createdAt >= :since')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', PointsPolicyDecision::STATUS_BLOCK)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count;
    }

    public function findLatestBlockedAtForCompany(int $companyId): ?\DateTimeImmutable
    {
        $blockedAt = $this->createQueryBuilder('d')
            ->select('d.createdAt')
            ->andWhere('d.company = :companyId')
            ->andWhere('d.decisionStatus = :status')
            ->setParameter('companyId', $companyId)
            ->setParameter('status', PointsPolicyDecision::STATUS_BLOCK)
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!is_array($blockedAt)) {
            return null;
        }

        $value = $blockedAt['createdAt'] ?? null;

        return $value instanceof \DateTimeImmutable ? $value : null;
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

    private function buildCompanyFilterQuery(
        int $companyId,
        ?string $decisionStatus,
        ?string $referenceType,
    ): \Doctrine\ORM\QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('d')
            ->andWhere('d.company = :companyId')
            ->setParameter('companyId', $companyId);

        if (null !== $decisionStatus && '' !== trim($decisionStatus)) {
            $queryBuilder
                ->andWhere('d.decisionStatus = :decisionStatus')
                ->setParameter('decisionStatus', strtoupper(trim($decisionStatus)));
        }

        if (null !== $referenceType && '' !== trim($referenceType)) {
            $queryBuilder
                ->andWhere('d.referenceType = :referenceType')
                ->setParameter('referenceType', strtoupper(trim($referenceType)));
        }

        return $queryBuilder;
    }
}
