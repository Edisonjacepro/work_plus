<?php

namespace App\Service\Billing;

interface CheckoutGatewayInterface
{
    public function supportsProvider(string $provider): bool;

    public function createCheckoutSession(CheckoutRequest $request): CheckoutSession;

    public function parseWebhookEvent(string $payload, ?string $signatureHeader = null): ?WebhookEvent;
}

