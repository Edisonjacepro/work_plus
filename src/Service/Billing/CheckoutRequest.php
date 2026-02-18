<?php

namespace App\Service\Billing;

final class CheckoutRequest
{
    /**
     * @param array<string, scalar> $metadata
     */
    public function __construct(
        public readonly int $companyId,
        public readonly string $customerEmail,
        public readonly string $planCode,
        public readonly int $amountCents,
        public readonly string $currencyCode,
        public readonly string $idempotencyKey,
        public readonly string $successUrl,
        public readonly string $cancelUrl,
        public readonly array $metadata = [],
    ) {
    }
}

