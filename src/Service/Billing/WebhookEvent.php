<?php

namespace App\Service\Billing;

final class WebhookEvent
{
    /**
     * @param array<string, scalar> $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $eventType,
        public readonly string $status,
        public readonly ?string $sessionId = null,
        public readonly ?string $paymentId = null,
        public readonly array $metadata = [],
    ) {
    }
}

