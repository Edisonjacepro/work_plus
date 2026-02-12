<?php

namespace App\Tests\Entity;

use App\Entity\Offer;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testAddOfferSetsAuthor(): void
    {
        $user = new User();
        $offer = new Offer();

        $user->addOffer($offer);

        self::assertSame($user, $offer->getAuthor());
        self::assertCount(1, $user->getOffers());
    }

    public function testDefaultAccountTypeIsCompany(): void
    {
        $user = new User();

        self::assertSame(User::ACCOUNT_TYPE_COMPANY, $user->getAccountType());
    }
}
