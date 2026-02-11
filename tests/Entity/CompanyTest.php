<?php

namespace App\Tests\Entity;

use App\Entity\Company;
use App\Entity\Offer;
use PHPUnit\Framework\TestCase;

class CompanyTest extends TestCase
{
    public function testAddOfferSetsCompany(): void
    {
        $company = new Company();
        $offer = new Offer();

        $company->addOffer($offer);

        self::assertSame($company, $offer->getCompany());
        self::assertCount(1, $company->getOffers());
    }
}
