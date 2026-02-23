<?php

namespace App\Repository;

use App\Entity\Application;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Application>
 */
class ApplicationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Application::class);
    }

    /**
     * @return list<Application>
     */
    public function findForRecruiter(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.offer', 'o')
            ->andWhere('o.author = :author')
            ->setParameter('author', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Application>
     */
    public function findForCandidate(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.candidate = :candidate')
            ->setParameter('candidate', $user)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: list<Application>, total: int}
     */
    public function findForRecruiterPaginated(User $user, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.offer', 'o')
            ->leftJoin('o.company', 'c')
            ->addSelect('o', 'c')
            ->andWhere('o.author = :author')
            ->setParameter('author', $user);

        $items = (clone $qb)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) (clone $qb)
            ->select('COUNT(a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array{items: list<Application>, total: int}
     */
    public function findForCandidatePaginated(User $user, int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $qb = $this->createQueryBuilder('a')
            ->innerJoin('a.offer', 'o')
            ->leftJoin('o.company', 'c')
            ->addSelect('o', 'c')
            ->andWhere('a.candidate = :candidate')
            ->setParameter('candidate', $user);

        $items = (clone $qb)
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) (clone $qb)
            ->select('COUNT(a.id)')
            ->resetDQLPart('orderBy')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }

    /**
     * @return array{items: list<Application>, total: int}
     */
    public function findAllPaginated(int $page, int $perPage): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $items = $this->createQueryBuilder('a')
            ->innerJoin('a.offer', 'o')
            ->leftJoin('o.company', 'c')
            ->addSelect('o', 'c')
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $total = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        return [
            'items' => $items,
            'total' => $total,
        ];
    }
}
