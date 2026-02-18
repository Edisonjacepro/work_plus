<?php

namespace App\Service\Billing;

final class CheckoutSession
{
    /**
     * @param array<string, mixed>|null $payload
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly ?string $sessionId = null,
        public readonly ?string $paymentId = null,
        public readonly ?string $checkoutUrl = null,
        public readonly ?array $payload = null,
    ) {
    }
}

