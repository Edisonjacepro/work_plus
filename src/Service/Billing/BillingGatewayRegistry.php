<?php

namespace App\Service\Billing;

class BillingGatewayRegistry
{
    /**
     * @param iterable<CheckoutGatewayInterface> $gateways
     */
    public function __construct(
        private readonly iterable $gateways,
    ) {
    }

    public function resolve(string $provider): CheckoutGatewayInterface
    {
        $normalizedProvider = strtolower(trim($provider));

        foreach ($this->gateways as $gateway) {
            if ($gateway->supportsProvider($normalizedProvider)) {
                return $gateway;
            }
        }

        throw new \InvalidArgumentException(sprintf('No checkout gateway found for provider "%s".', $normalizedProvider));
    }
}

