<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class RequestRateLimiterService
{
    public function __construct(
        #[Autowire(service: 'limiter.offer_publish')]
        private readonly RateLimiterFactory $offerPublishLimiter,
        #[Autowire(service: 'limiter.points_claim_submit')]
        private readonly RateLimiterFactory $pointsClaimSubmitLimiter,
        #[Autowire(service: 'limiter.application_hire')]
        private readonly RateLimiterFactory $applicationHireLimiter,
    ) {
    }

    public function consumeOfferPublish(User $user): void
    {
        $this->consume(
            $this->offerPublishLimiter,
            $this->buildKey($user, 'offer_publish'),
            'Trop de tentatives de publication. Veuillez reessayer plus tard.',
        );
    }

    public function consumePointsClaimSubmit(User $user): void
    {
        $this->consume(
            $this->pointsClaimSubmitLimiter,
            $this->buildKey($user, 'points_claim_submit'),
            'Trop de demandes de points. Veuillez reessayer plus tard.',
        );
    }

    public function consumeApplicationHire(User $user): void
    {
        $this->consume(
            $this->applicationHireLimiter,
            $this->buildKey($user, 'application_hire'),
            'Trop de validations d embauche. Veuillez reessayer plus tard.',
        );
    }

    private function consume(RateLimiterFactory $factory, string $key, string $message): void
    {
        $limit = $factory->create($key)->consume(1);
        if ($limit->isAccepted()) {
            return;
        }

        $retryAfterSeconds = max(1, $limit->getRetryAfter()->getTimestamp() - time());

        throw new TooManyRequestsHttpException($retryAfterSeconds, $message);
    }

    private function buildKey(User $user, string $scope): string
    {
        $userId = $user->getId();
        $identity = null !== $userId ? (string) $userId : hash('sha256', (string) $user->getUserIdentifier());

        return $scope . ':' . $identity;
    }
}
