<?php

namespace App\Repository;

use App\Entity\RecruiterSubscriptionPayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecruiterSubscriptionPayment>
 */
class RecruiterSubscriptionPaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecruiterSubscriptionPayment::class);
    }

    public function findOneByIdempotencyKey(string $idempotencyKey): ?RecruiterSubscriptionPayment
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.idempotencyKey = :key')
            ->setParameter('key', $idempotencyKey)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByProviderSessionId(string $provider, string $providerSessionId): ?RecruiterSubscriptionPayment
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.provider = :provider')
            ->andWhere('p.providerSessionId = :providerSessionId')
            ->setParameter('provider', strtolower($provider))
            ->setParameter('providerSessionId', $providerSessionId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByProviderPaymentId(string $provider, string $providerPaymentId): ?RecruiterSubscriptionPayment
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.provider = :provider')
            ->andWhere('p.providerPaymentId = :providerPaymentId')
            ->setParameter('provider', strtolower($provider))
            ->setParameter('providerPaymentId', $providerPaymentId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}

