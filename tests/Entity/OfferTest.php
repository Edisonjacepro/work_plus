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

    public function testPublishedAtIsNullByDefault(): void
    {
        $offer = new Offer();

        self::assertNull($offer->getPublishedAt());
    }

    public function testDefaultModerationStatusIsDraft(): void
    {
        $offer = new Offer();

        self::assertSame(Offer::MODERATION_STATUS_DRAFT, $offer->getModerationStatus());
    }

    public function testCanSetPublishedAt(): void
    {
        $offer = new Offer();
        $publishedAt = new \DateTimeImmutable('2026-02-13 10:00:00');

        $offer->setPublishedAt($publishedAt);

        self::assertSame($publishedAt, $offer->getPublishedAt());
    }
}
