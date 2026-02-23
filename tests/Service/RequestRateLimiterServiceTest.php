<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\RequestRateLimiterService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class RequestRateLimiterServiceTest extends TestCase
{
    public function testConsumeOfferPublishAllowsFirstRequest(): void
    {
        $service = $this->buildServiceWithLimit(1);
        $user = (new User())
            ->setEmail('rate-limit@example.test')
            ->setPassword('test-password');

        $service->consumeOfferPublish($user);
        self::assertTrue(true);
    }

    public function testConsumeOfferPublishThrowsTooManyRequestsWhenLimitExceeded(): void
    {
        $service = $this->buildServiceWithLimit(1);
        $user = (new User())
            ->setEmail('rate-limit@example.test')
            ->setPassword('test-password');

        $service->consumeOfferPublish($user);

        try {
            $service->consumeOfferPublish($user);
            self::fail('Expected TooManyRequestsHttpException was not thrown.');
        } catch (TooManyRequestsHttpException $exception) {
            self::assertSame(429, $exception->getStatusCode());
            self::assertArrayHasKey('Retry-After', $exception->getHeaders());
        }
    }

    public function testLimitersAreScopedByAction(): void
    {
        $factoryOffer = $this->createFactory('offer_publish', 1);
        $factoryPoints = $this->createFactory('points_claim_submit', 1);
        $factoryHire = $this->createFactory('application_hire', 1);
        $service = new RequestRateLimiterService($factoryOffer, $factoryPoints, $factoryHire);

        $user = (new User())
            ->setEmail('rate-limit@example.test')
            ->setPassword('test-password');

        $service->consumeOfferPublish($user);
        $service->consumePointsClaimSubmit($user);
        $service->consumeApplicationHire($user);

        self::assertTrue(true);
    }

    private function buildServiceWithLimit(int $limit): RequestRateLimiterService
    {
        $factoryOffer = $this->createFactory('offer_publish', $limit);
        $factoryPoints = $this->createFactory('points_claim_submit', $limit);
        $factoryHire = $this->createFactory('application_hire', $limit);

        return new RequestRateLimiterService($factoryOffer, $factoryPoints, $factoryHire);
    }

    private function createFactory(string $id, int $limit): RateLimiterFactory
    {
        return new RateLimiterFactory([
            'id' => $id,
            'policy' => 'fixed_window',
            'limit' => $limit,
            'interval' => '1 hour',
        ], new InMemoryStorage());
    }
}
