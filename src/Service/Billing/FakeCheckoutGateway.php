<?php

namespace App\Service\Billing;

use App\Entity\RecruiterSubscriptionPayment;

class FakeCheckoutGateway implements CheckoutGatewayInterface
{
    public function supportsProvider(string $provider): bool
    {
        return RecruiterSubscriptionPayment::PROVIDER_FAKE === strtolower($provider);
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession
    {
        $token = substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $sessionId = 'fake_cs_' . $token;
        $paymentId = 'fake_pi_' . $token;

        return new CheckoutSession(
            provider: RecruiterSubscriptionPayment::PROVIDER_FAKE,
            status: RecruiterSubscriptionPayment::STATUS_SUCCEEDED,
            sessionId: $sessionId,
            paymentId: $paymentId,
            checkoutUrl: null,
            payload: [
                'event' => 'payment.completed',
                'checkoutUrl' => null,
            ],
        );
    }

    public function parseWebhookEvent(string $payload, ?string $signatureHeader = null): ?WebhookEvent
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $status = strtoupper((string) ($decoded['status'] ?? ''));
        if (!in_array($status, [
            RecruiterSubscriptionPayment::STATUS_SUCCEEDED,
            RecruiterSubscriptionPayment::STATUS_FAILED,
            RecruiterSubscriptionPayment::STATUS_CANCELED,
        ], true)) {
            return null;
        }

        $eventType = (string) ($decoded['event'] ?? 'manual.event');
        $metadata = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];

        return new WebhookEvent(
            provider: RecruiterSubscriptionPayment::PROVIDER_FAKE,
            eventType: $eventType,
            status: $status,
            sessionId: isset($decoded['session_id']) ? (string) $decoded['session_id'] : null,
            paymentId: isset($decoded['payment_id']) ? (string) $decoded['payment_id'] : null,
            metadata: $this->normalizeMetadata($metadata),
        );
    }

    /**
     * @param array<mixed> $metadata
     * @return array<string, scalar>
     */
    private function normalizeMetadata(array $metadata): array
    {
        $normalized = [];
        foreach ($metadata as $key => $value) {
            if (!is_string($key) || (!is_scalar($value) && null !== $value)) {
                continue;
            }

            $normalized[$key] = null === $value ? '' : $value;
        }

        return $normalized;
    }
}

