<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\RecruiterSubscriptionPayment;
use App\Entity\User;
use App\Repository\RecruiterSubscriptionPaymentRepository;
use App\Service\Billing\BillingGatewayRegistry;
use App\Service\Billing\CheckoutSession;
use App\Service\Billing\RecruiterPlanCatalog;
use App\Service\Billing\RecruiterSubscriptionService;
use App\Service\Billing\WebhookEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RecruiterSubscriptionServiceTest extends TestCase
{
    public function testStartCheckoutActivatesPlanWhenGatewayReturnsSucceeded(): void
    {
        $repository = $this->createMock(RecruiterSubscriptionPaymentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $gatewayRegistry = $this->createMock(BillingGatewayRegistry::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $planCatalog = new RecruiterPlanCatalog();

        $gateway = new class() implements \App\Service\Billing\CheckoutGatewayInterface {
            public function supportsProvider(string $provider): bool
            {
                return 'fake' === $provider;
            }

            public function createCheckoutSession(\App\Service\Billing\CheckoutRequest $request): CheckoutSession
            {
                return new CheckoutSession(
                    provider: RecruiterSubscriptionPayment::PROVIDER_FAKE,
                    status: RecruiterSubscriptionPayment::STATUS_SUCCEEDED,
                    sessionId: 'fake_session_1',
                    paymentId: 'fake_payment_1',
                    checkoutUrl: null,
                    payload: ['source' => 'test'],
                );
            }

            public function parseWebhookEvent(string $payload, ?string $signatureHeader = null): ?WebhookEvent
            {
                return null;
            }
        };

        $service = new RecruiterSubscriptionService(
            $repository,
            $entityManager,
            $planCatalog,
            $gatewayRegistry,
            $urlGenerator,
            'fake',
        );

        $company = (new Company())->setName('Work Plus');
        $actor = (new User())->setEmail('recruiter@example.test')->setAccountType(User::ACCOUNT_TYPE_COMPANY)->setCompany($company);
        $this->setEntityId($company, 11);
        $this->setEntityId($actor, 45);

        $repository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->willReturn(null);

        $gatewayRegistry->expects(self::once())
            ->method('resolve')
            ->with('fake')
            ->willReturn($gateway);

        $urlGenerator->expects(self::exactly(2))
            ->method('generate')
            ->willReturn('http://localhost/pricing');

        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::isInstanceOf(RecruiterSubscriptionPayment::class));

        $result = $service->startCheckout($company, $actor, Company::RECRUITER_PLAN_GROWTH);

        self::assertTrue($result->immediateSuccess);
        self::assertFalse($result->alreadyProcessed);
        self::assertSame(Company::RECRUITER_PLAN_GROWTH, $company->getRecruiterPlanCode());
        self::assertInstanceOf(\DateTimeImmutable::class, $company->getRecruiterPlanExpiresAt());
    }

    public function testStartCheckoutUsesIdempotencyForCurrentPeriod(): void
    {
        $repository = $this->createMock(RecruiterSubscriptionPaymentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $gatewayRegistry = $this->createMock(BillingGatewayRegistry::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $planCatalog = new RecruiterPlanCatalog();

        $service = new RecruiterSubscriptionService(
            $repository,
            $entityManager,
            $planCatalog,
            $gatewayRegistry,
            $urlGenerator,
            'fake',
        );

        $company = (new Company())->setName('Work Plus');
        $actor = (new User())->setEmail('recruiter@example.test')->setAccountType(User::ACCOUNT_TYPE_COMPANY)->setCompany($company);
        $this->setEntityId($company, 12);

        $existingPayment = (new RecruiterSubscriptionPayment())
            ->setCompany($company)
            ->setPlanCode(Company::RECRUITER_PLAN_SCALE)
            ->setAmountCents(9900)
            ->setCurrencyCode('EUR')
            ->setProvider(RecruiterSubscriptionPayment::PROVIDER_FAKE)
            ->setStatus(RecruiterSubscriptionPayment::STATUS_SUCCEEDED)
            ->setIdempotencyKey('existing_key')
            ->setPeriodStart(new \DateTimeImmutable('2026-02-01 00:00:00'))
            ->setPeriodEnd(new \DateTimeImmutable('2026-03-01 00:00:00'))
            ->setPaidAt(new \DateTimeImmutable('2026-02-02 10:00:00'));

        $repository->expects(self::once())
            ->method('findOneByIdempotencyKey')
            ->willReturn($existingPayment);

        $gatewayRegistry->expects(self::never())->method('resolve');
        $entityManager->expects(self::never())->method('persist');

        $result = $service->startCheckout($company, $actor, Company::RECRUITER_PLAN_SCALE);

        self::assertTrue($result->alreadyProcessed);
        self::assertTrue($result->immediateSuccess);
        self::assertSame(Company::RECRUITER_PLAN_SCALE, $company->getRecruiterPlanCode());
    }

    public function testHandleWebhookEventMarksPaymentAsSucceeded(): void
    {
        $repository = $this->createMock(RecruiterSubscriptionPaymentRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $gatewayRegistry = $this->createMock(BillingGatewayRegistry::class);
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $planCatalog = new RecruiterPlanCatalog();

        $service = new RecruiterSubscriptionService(
            $repository,
            $entityManager,
            $planCatalog,
            $gatewayRegistry,
            $urlGenerator,
            'fake',
        );

        $company = (new Company())->setName('Work Plus');
        $this->setEntityId($company, 13);

        $payment = (new RecruiterSubscriptionPayment())
            ->setCompany($company)
            ->setPlanCode(Company::RECRUITER_PLAN_GROWTH)
            ->setAmountCents(3900)
            ->setCurrencyCode('EUR')
            ->setProvider(RecruiterSubscriptionPayment::PROVIDER_STRIPE)
            ->setProviderSessionId('cs_test_1')
            ->setStatus(RecruiterSubscriptionPayment::STATUS_PENDING)
            ->setIdempotencyKey('idempotency')
            ->setPeriodStart(new \DateTimeImmutable('2026-02-01 00:00:00'))
            ->setPeriodEnd(new \DateTimeImmutable('2026-03-01 00:00:00'));

        $repository->expects(self::once())
            ->method('findOneByProviderSessionId')
            ->with(RecruiterSubscriptionPayment::PROVIDER_STRIPE, 'cs_test_1')
            ->willReturn($payment);

        $repository->expects(self::never())->method('findOneByProviderPaymentId');

        $event = new WebhookEvent(
            provider: RecruiterSubscriptionPayment::PROVIDER_STRIPE,
            eventType: 'checkout.session.completed',
            status: RecruiterSubscriptionPayment::STATUS_SUCCEEDED,
            sessionId: 'cs_test_1',
            paymentId: 'sub_123',
            metadata: ['plan' => 'GROWTH'],
        );

        $updated = $service->handleWebhookEvent($event);

        self::assertInstanceOf(RecruiterSubscriptionPayment::class, $updated);
        self::assertSame(RecruiterSubscriptionPayment::STATUS_SUCCEEDED, $updated->getStatus());
        self::assertSame('sub_123', $updated->getProviderPaymentId());
        self::assertInstanceOf(\DateTimeImmutable::class, $updated->getPaidAt());
        self::assertSame(Company::RECRUITER_PLAN_GROWTH, $company->getRecruiterPlanCode());
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}

