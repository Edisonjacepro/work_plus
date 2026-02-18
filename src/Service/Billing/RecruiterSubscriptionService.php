<?php

namespace App\Service\Billing;

use App\Entity\Company;
use App\Entity\RecruiterSubscriptionPayment;
use App\Entity\User;
use App\Repository\RecruiterSubscriptionPaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RecruiterSubscriptionService
{
    public function __construct(
        private readonly RecruiterSubscriptionPaymentRepository $paymentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly RecruiterPlanCatalog $planCatalog,
        private readonly BillingGatewayRegistry $billingGatewayRegistry,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $billingProvider,
    ) {
    }

    public function startCheckout(Company $company, User $actor, string $planCode): CheckoutStartResult
    {
        $companyId = $company->getId();
        if (null === $companyId) {
            throw new \InvalidArgumentException('Entreprise invalide pour le paiement.');
        }

        $plan = $this->planCatalog->getPaidPlanOrFail($planCode);
        $periodStart = $this->buildCurrentPeriodStart();
        $periodEnd = $periodStart->modify('+1 month');
        $idempotencyKey = $this->buildIdempotencyKey($companyId, $plan['code'], $periodStart);

        $existingPayment = $this->paymentRepository->findOneByIdempotencyKey($idempotencyKey);
        if ($existingPayment instanceof RecruiterSubscriptionPayment) {
            if ($existingPayment->isSucceeded()) {
                $this->applyPaidPlan($company, $existingPayment);
            }

            return new CheckoutStartResult(
                payment: $existingPayment,
                redirectUrl: $this->extractRedirectUrl($existingPayment),
                immediateSuccess: $existingPayment->isSucceeded(),
                alreadyProcessed: true,
            );
        }

        $gateway = $this->billingGatewayRegistry->resolve($this->billingProvider);
        $session = $gateway->createCheckoutSession(new CheckoutRequest(
            companyId: $companyId,
            customerEmail: (string) ($actor->getEmail() ?? ''),
            planCode: $plan['code'],
            amountCents: $plan['priceCents'],
            currencyCode: $plan['currencyCode'],
            idempotencyKey: $idempotencyKey,
            successUrl: $this->urlGenerator->generate('pricing_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            cancelUrl: $this->urlGenerator->generate('pricing_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            metadata: [
                'company_id' => $companyId,
                'plan_code' => $plan['code'],
                'period_start' => $periodStart->format(DATE_ATOM),
                'period_end' => $periodEnd->format(DATE_ATOM),
            ],
        ));

        $payment = (new RecruiterSubscriptionPayment())
            ->setCompany($company)
            ->setInitiatedBy($actor)
            ->setPlanCode($plan['code'])
            ->setAmountCents($plan['priceCents'])
            ->setCurrencyCode($plan['currencyCode'])
            ->setProvider($session->provider)
            ->setProviderSessionId($session->sessionId)
            ->setProviderPaymentId($session->paymentId)
            ->setStatus($this->normalizeStatus($session->status))
            ->setIdempotencyKey($idempotencyKey)
            ->setPeriodStart($periodStart)
            ->setPeriodEnd($periodEnd)
            ->setProviderPayload($this->buildProviderPayload($session));

        if ($payment->isSucceeded()) {
            $payment->setPaidAt(new \DateTimeImmutable());
            $this->applyPaidPlan($company, $payment);
        }

        $this->entityManager->persist($payment);

        return new CheckoutStartResult(
            payment: $payment,
            redirectUrl: $session->checkoutUrl,
            immediateSuccess: $payment->isSucceeded(),
            alreadyProcessed: false,
        );
    }

    public function handleWebhookEvent(WebhookEvent $event): ?RecruiterSubscriptionPayment
    {
        $payment = null;
        if (null !== $event->sessionId && '' !== $event->sessionId) {
            $payment = $this->paymentRepository->findOneByProviderSessionId($event->provider, $event->sessionId);
        }

        if (!$payment instanceof RecruiterSubscriptionPayment && null !== $event->paymentId && '' !== $event->paymentId) {
            $payment = $this->paymentRepository->findOneByProviderPaymentId($event->provider, $event->paymentId);
        }

        if (!$payment instanceof RecruiterSubscriptionPayment) {
            return null;
        }

        if ($payment->isSucceeded() && RecruiterSubscriptionPayment::STATUS_SUCCEEDED !== $event->status) {
            return $payment;
        }

        $payment
            ->setStatus($this->normalizeStatus($event->status))
            ->setProviderPaymentId($event->paymentId ?? $payment->getProviderPaymentId())
            ->setProviderSessionId($event->sessionId ?? $payment->getProviderSessionId());

        $existingPayload = $payment->getProviderPayload() ?? [];
        $existingPayload['lastWebhookType'] = $event->eventType;
        $existingPayload['lastWebhookMetadata'] = $event->metadata;
        $payment->setProviderPayload($existingPayload);

        if (RecruiterSubscriptionPayment::STATUS_SUCCEEDED === $payment->getStatus()) {
            if (!$payment->getPaidAt() instanceof \DateTimeImmutable) {
                $payment->setPaidAt(new \DateTimeImmutable());
            }
            $this->applyPaidPlan($payment->getCompany(), $payment);
        }

        return $payment;
    }

    private function buildCurrentPeriodStart(): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('now'))->setTime(0, 0)->modify('first day of this month');
    }

    private function buildIdempotencyKey(int $companyId, string $planCode, \DateTimeImmutable $periodStart): string
    {
        return sprintf(
            'recruiter_plan_%d_%s_%s',
            $companyId,
            strtolower($planCode),
            $periodStart->format('Ym')
        );
    }

    private function normalizeStatus(string $status): string
    {
        $normalized = strtoupper($status);

        return match ($normalized) {
            RecruiterSubscriptionPayment::STATUS_SUCCEEDED,
            RecruiterSubscriptionPayment::STATUS_FAILED,
            RecruiterSubscriptionPayment::STATUS_CANCELED => $normalized,
            default => RecruiterSubscriptionPayment::STATUS_PENDING,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProviderPayload(CheckoutSession $session): array
    {
        $payload = is_array($session->payload) ? $session->payload : [];
        $payload['checkoutUrl'] = $session->checkoutUrl;

        return $payload;
    }

    private function extractRedirectUrl(RecruiterSubscriptionPayment $payment): ?string
    {
        $payload = $payment->getProviderPayload();
        $checkoutUrl = $payload['checkoutUrl'] ?? null;

        return is_string($checkoutUrl) && '' !== trim($checkoutUrl) ? $checkoutUrl : null;
    }

    private function applyPaidPlan(?Company $company, RecruiterSubscriptionPayment $payment): void
    {
        if (!$company instanceof Company) {
            return;
        }

        $periodStart = $payment->getPeriodStart();
        $periodEnd = $payment->getPeriodEnd();
        if (!$periodStart instanceof \DateTimeImmutable || !$periodEnd instanceof \DateTimeImmutable) {
            return;
        }

        $company
            ->setRecruiterPlanCode($payment->getPlanCode())
            ->setRecruiterPlanStartedAt($periodStart)
            ->setRecruiterPlanExpiresAt($periodEnd);
    }
}
