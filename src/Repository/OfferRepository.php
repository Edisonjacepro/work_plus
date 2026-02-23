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

    /**
     * @return Offer[]
     */
    public function findByAuthor(int $authorId): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.author = :authorId')
            ->setParameter('authorId', $authorId)
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Offer[]
     */
    public function findPublicPublished(): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.isVisible = :visible')
            ->andWhere('o.status = :status')
            ->andWhere('o.moderationStatus = :moderationStatus')
            ->setParameter('visible', true)
            ->setParameter('status', Offer::STATUS_PUBLISHED)
            ->setParameter('moderationStatus', Offer::MODERATION_STATUS_APPROVED)
            ->orderBy('o.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: list<Offer>, total: int}
     */
    public function findPublicPublishedPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $qb = $this->createQueryBuilder('o')
            ->innerJoin('o.company', 'c')
            ->addSelect('c')
            ->andWhere('o.isVisible = :visible')
            ->andWhere('o.status = :status')
            ->andWhere('o.moderationStatus = :moderationStatus')
            ->setParameter('visible', true)
            ->setParameter('status', Offer::STATUS_PUBLISHED)
            ->setParameter('moderationStatus', Offer::MODERATION_STATUS_APPROVED);

        $items = (clone $qb)
            ->orderBy('o.publishedAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) (clone $qb)
            ->select('COUNT(o.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array{items: list<Offer>, total: int}
     */
    public function findByAuthorPaginated(int $authorId, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $qb = $this->createQueryBuilder('o')
            ->innerJoin('o.company', 'c')
            ->addSelect('c')
            ->andWhere('o.author = :authorId')
            ->setParameter('authorId', $authorId);

        $items = (clone $qb)
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) (clone $qb)
            ->select('COUNT(o.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array{items: list<Offer>, total: int}
     */
    public function findAllPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $items = $this->createQueryBuilder('o')
            ->innerJoin('o.company', 'c')
            ->addSelect('c')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
