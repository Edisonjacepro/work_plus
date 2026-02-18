<?php

namespace App\Service\Billing;

use App\Entity\RecruiterSubscriptionPayment;

class StripeCheckoutGateway implements CheckoutGatewayInterface
{
    public function __construct(
        private readonly string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly int $webhookToleranceSeconds = 300,
    ) {
    }

    public function supportsProvider(string $provider): bool
    {
        return RecruiterSubscriptionPayment::PROVIDER_STRIPE === strtolower($provider);
    }

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession
    {
        if ('' === trim($this->stripeSecretKey)) {
            throw new \RuntimeException('Stripe secret key is missing. Configure STRIPE_SECRET_KEY.');
        }

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Stripe checkout requires the curl PHP extension.');
        }

        $payload = $this->buildCheckoutPayload($request);
        $response = $this->executeStripeRequest(
            url: 'https://api.stripe.com/v1/checkout/sessions',
            payload: $payload,
            idempotencyKey: $request->idempotencyKey,
        );

        $sessionId = isset($response['id']) ? (string) $response['id'] : null;
        $checkoutUrl = isset($response['url']) ? (string) $response['url'] : null;

        return new CheckoutSession(
            provider: RecruiterSubscriptionPayment::PROVIDER_STRIPE,
            status: RecruiterSubscriptionPayment::STATUS_PENDING,
            sessionId: $sessionId,
            paymentId: null,
            checkoutUrl: $checkoutUrl,
            payload: $response,
        );
    }

    public function parseWebhookEvent(string $payload, ?string $signatureHeader = null): ?WebhookEvent
    {
        $this->assertValidSignature($payload, $signatureHeader);

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new \InvalidArgumentException('Stripe webhook payload is invalid JSON.');
        }

        $eventType = (string) ($event['type'] ?? '');
        $dataObject = $event['data']['object'] ?? null;
        if (!is_array($dataObject)) {
            return null;
        }

        $metadata = is_array($dataObject['metadata'] ?? null) ? $this->normalizeMetadata($dataObject['metadata']) : [];
        $sessionId = isset($dataObject['id']) ? (string) $dataObject['id'] : null;
        $paymentId = $this->extractProviderPaymentId($dataObject);

        return match ($eventType) {
            'checkout.session.completed' => new WebhookEvent(
                provider: RecruiterSubscriptionPayment::PROVIDER_STRIPE,
                eventType: $eventType,
                status: RecruiterSubscriptionPayment::STATUS_SUCCEEDED,
                sessionId: $sessionId,
                paymentId: $paymentId,
                metadata: $metadata,
            ),
            'checkout.session.expired' => new WebhookEvent(
                provider: RecruiterSubscriptionPayment::PROVIDER_STRIPE,
                eventType: $eventType,
                status: RecruiterSubscriptionPayment::STATUS_CANCELED,
                sessionId: $sessionId,
                paymentId: $paymentId,
                metadata: $metadata,
            ),
            'checkout.session.async_payment_failed', 'payment_intent.payment_failed' => new WebhookEvent(
                provider: RecruiterSubscriptionPayment::PROVIDER_STRIPE,
                eventType: $eventType,
                status: RecruiterSubscriptionPayment::STATUS_FAILED,
                sessionId: $sessionId,
                paymentId: $paymentId,
                metadata: $metadata,
            ),
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCheckoutPayload(CheckoutRequest $request): array
    {
        $fields = [
            'mode' => 'subscription',
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'client_reference_id' => (string) $request->companyId,
            'line_items[0][quantity]' => '1',
            'line_items[0][price_data][currency]' => strtolower($request->currencyCode),
            'line_items[0][price_data][unit_amount]' => (string) $request->amountCents,
            'line_items[0][price_data][recurring][interval]' => 'month',
            'line_items[0][price_data][product_data][name]' => sprintf('Work+ %s', $request->planCode),
            'customer_email' => $request->customerEmail,
        ];

        foreach ($request->metadata as $key => $value) {
            $fields[sprintf('metadata[%s]', $key)] = (string) $value;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function executeStripeRequest(string $url, array $payload, string $idempotencyKey): array
    {
        $curl = curl_init($url);
        if (false === $curl) {
            throw new \RuntimeException('Unable to initialize Stripe request.');
        }

        $httpHeaders = [
            'Authorization: Bearer ' . $this->stripeSecretKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: ' . $idempotencyKey,
        ];

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_POSTFIELDS => http_build_query($payload),
        ]);

        $rawResponse = curl_exec($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if (false === $rawResponse) {
            throw new \RuntimeException('Stripe request failed: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Stripe response is not valid JSON.');
        }

        if ($statusCode >= 400) {
            throw new \RuntimeException('Stripe API error while creating checkout session.');
        }

        return $decoded;
    }

    private function assertValidSignature(string $payload, ?string $signatureHeader): void
    {
        if ('' === trim($this->stripeWebhookSecret)) {
            throw new \RuntimeException('Stripe webhook secret is missing. Configure STRIPE_WEBHOOK_SECRET.');
        }

        if (null === $signatureHeader || '' === trim($signatureHeader)) {
            throw new \InvalidArgumentException('Missing Stripe-Signature header.');
        }

        [$timestamp, $signature] = $this->parseSignatureHeader($signatureHeader);
        if (0 === $timestamp || '' === $signature) {
            throw new \InvalidArgumentException('Invalid Stripe-Signature header.');
        }

        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $this->stripeWebhookSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            throw new \InvalidArgumentException('Invalid Stripe webhook signature.');
        }

        if (abs(time() - $timestamp) > $this->webhookToleranceSeconds) {
            throw new \InvalidArgumentException('Stripe webhook timestamp is outside tolerance.');
        }
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function parseSignatureHeader(string $signatureHeader): array
    {
        $timestamp = 0;
        $signature = '';

        foreach (explode(',', $signatureHeader) as $part) {
            $trimmed = trim($part);
            if (str_starts_with($trimmed, 't=')) {
                $timestamp = (int) substr($trimmed, 2);
                continue;
            }

            if (str_starts_with($trimmed, 'v1=')) {
                $signature = (string) substr($trimmed, 3);
            }
        }

        return [$timestamp, $signature];
    }

    /**
     * @param array<string, mixed> $dataObject
     */
    private function extractProviderPaymentId(array $dataObject): ?string
    {
        if (isset($dataObject['payment_intent']) && is_string($dataObject['payment_intent'])) {
            return $dataObject['payment_intent'];
        }

        if (isset($dataObject['subscription']) && is_string($dataObject['subscription'])) {
            return $dataObject['subscription'];
        }

        if (isset($dataObject['id']) && is_string($dataObject['id'])) {
            return $dataObject['id'];
        }

        return null;
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
