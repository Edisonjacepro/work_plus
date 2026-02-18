<?php

namespace App\Service\Billing;

use App\Entity\RecruiterSubscriptionPayment;

final class CheckoutStartResult
{
    public function __construct(
        public readonly RecruiterSubscriptionPayment $payment,
        public readonly ?string $redirectUrl,
        public readonly bool $immediateSuccess,
        public readonly bool $alreadyProcessed,
    ) {
    }
}

