<?php

namespace App\Tests\Entity;

use App\Entity\Offer;
use PHPUnit\Framework\TestCase;

class OfferTest extends TestCase
{
    public function testDefaultStatusIsDraft(): void
    {
        $offer = new Offer();

        self::assertSame(Offer::STATUS_DRAFT, $offer->getStatus());
    }

    public function testIsVisibleByDefault(): void
    {
        $offer = new Offer();

        self::assertTrue($offer->isVisible());
    }
}
