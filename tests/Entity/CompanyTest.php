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

    public function testCompanyProfileFieldsCanBeSet(): void
    {
        $company = new Company();
        $company
            ->setName('Work Plus')
            ->setDescription('Entreprise orientee impact')
            ->setWebsite('https://workplus.example')
            ->setCity('Paris')
            ->setSector('Economie sociale')
            ->setCompanySize('11-50');

        self::assertSame('Work Plus', $company->getName());
        self::assertSame('Entreprise orientee impact', $company->getDescription());
        self::assertSame('https://workplus.example', $company->getWebsite());
        self::assertSame('Paris', $company->getCity());
        self::assertSame('Economie sociale', $company->getSector());
        self::assertSame('11-50', $company->getCompanySize());
    }

    public function testRecruiterPlanCanBeActivated(): void
    {
        $company = (new Company())->setName('Work Plus');
        $start = new \DateTimeImmutable('2026-02-01 00:00:00');
        $end = new \DateTimeImmutable('2026-03-01 00:00:00');

        $company
            ->setRecruiterPlanCode(Company::RECRUITER_PLAN_GROWTH)
            ->setRecruiterPlanStartedAt($start)
            ->setRecruiterPlanExpiresAt($end);

        self::assertSame(Company::RECRUITER_PLAN_GROWTH, $company->getRecruiterPlanCode());
        self::assertSame($start, $company->getRecruiterPlanStartedAt());
        self::assertSame($end, $company->getRecruiterPlanExpiresAt());
        self::assertTrue($company->hasActivePaidPlan(new \DateTimeImmutable('2026-02-15 00:00:00')));
        self::assertFalse($company->hasActivePaidPlan(new \DateTimeImmutable('2026-03-02 00:00:00')));
    }
}
