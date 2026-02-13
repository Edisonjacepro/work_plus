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
}
