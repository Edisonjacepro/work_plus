<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\ImpactScore;
use App\Entity\Offer;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsLedgerEntryRepository;
use App\Service\PointsLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PointsLedgerServiceTest extends TestCase
{
    public function testAwardOfferPublicationPointsCreatesCreditEntry(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $entityManager);

        $offer = $this->buildOfferWithId(10);
        $impactScore = (new ImpactScore())
            ->setTotalScore(80)
            ->setSocietyScore(50)
            ->setBiodiversityScore(50)
            ->setGhgScore(50)
            ->setConfidence(0.9);

        $repository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('offer_publication_company_10')
            ->willReturn(false);

        $entityManager->expects(self::once())
            ->method('persist')
            ->with(self::callback(function (mixed $entry): bool {
                if (!$entry instanceof PointsLedgerEntry) {
                    return false;
                }

                return PointsLedgerEntry::TYPE_CREDIT === $entry->getEntryType()
                    && 95 === $entry->getPoints()
                    && 'offer_publication_company_10' === $entry->getIdempotencyKey()
                    && PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION === $entry->getReferenceType();
            }));

        $entry = $service->awardOfferPublicationPoints($offer, $impactScore);

        self::assertInstanceOf(PointsLedgerEntry::class, $entry);
        self::assertSame(95, $entry->getPoints());
    }

    public function testAwardOfferPublicationPointsSkipsExistingIdempotencyKey(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $entityManager);

        $offer = $this->buildOfferWithId(11);
        $impactScore = (new ImpactScore())
            ->setTotalScore(70)
            ->setSocietyScore(50)
            ->setBiodiversityScore(40)
            ->setGhgScore(45)
            ->setConfidence(0.85);

        $repository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('offer_publication_company_11')
            ->willReturn(true);

        $entityManager->expects(self::never())->method('persist');

        $entry = $service->awardOfferPublicationPoints($offer, $impactScore);

        self::assertNull($entry);
    }

    public function testGetCompanyBalanceUsesRepositoryAggregation(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $entityManager);

        $company = (new Company())->setName('Work Plus');
        $this->setEntityId($company, 5);

        $repository->expects(self::once())
            ->method('getCompanyBalance')
            ->with(5)
            ->willReturn(123);

        self::assertSame(123, $service->getCompanyBalance($company));
    }

    private function buildOfferWithId(int $id): Offer
    {
        $company = (new Company())->setName('Work Plus');
        $this->setEntityId($company, 42);

        $author = (new User())->setEmail('author@example.com');
        $this->setEntityId($author, 99);

        $offer = (new Offer())
            ->setTitle('Offer')
            ->setDescription('Description')
            ->setCompany($company)
            ->setAuthor($author);

        $this->setEntityId($offer, $id);

        return $offer;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
