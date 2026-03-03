<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Entity\ImpactScore;
use App\Entity\Offer;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsLedgerEntryRepository;
use App\Service\PointsLedgerService;
use App\Service\PointsPolicyAuditService;
use App\Service\PointsPolicyService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class PointsLedgerServiceTest extends TestCase
{
    public function testAwardOfferPublicationPointsCreatesCreditEntry(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $policyService, $policyAuditService, $entityManager);

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
        $policyService->expects(self::once())
            ->method('evaluateCompanyCredit')
            ->with($offer->getCompany(), 95, PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION)
            ->willReturn(null);
        $policyAuditService->expects(self::once())
            ->method('recordCompanyDecision')
            ->with(
                $offer->getCompany(),
                95,
                PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION,
                10,
                null,
                [
                    'offerId' => 10,
                    'impactScore' => 80,
                ],
            );

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
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $policyService, $policyAuditService, $entityManager);

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
        $policyService->expects(self::never())->method('evaluateCompanyCredit');
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');

        $entityManager->expects(self::never())->method('persist');

        $entry = $service->awardOfferPublicationPoints($offer, $impactScore);

        self::assertNull($entry);
    }

    public function testAwardOfferPublicationPointsSkipsWhenPolicyBlocksCredit(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createMock(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $policyService, $policyAuditService, $entityManager);

        $offer = $this->buildOfferWithId(12);
        $impactScore = (new ImpactScore())
            ->setTotalScore(70)
            ->setSocietyScore(50)
            ->setBiodiversityScore(40)
            ->setGhgScore(45)
            ->setConfidence(0.85);

        $repository->expects(self::once())
            ->method('existsByIdempotencyKey')
            ->with('offer_publication_company_12')
            ->willReturn(false);
        $policyService->expects(self::once())
            ->method('evaluateCompanyCredit')
            ->with($offer->getCompany(), 85, PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION)
            ->willReturn([
                'reasonCode' => 'FREEMIUM_MONTHLY_OFFER_PUBLICATION_CAP',
                'reasonText' => 'Quota depasse.',
                'metadata' => [],
            ]);
        $policyAuditService->expects(self::once())
            ->method('recordCompanyDecision')
            ->with(
                $offer->getCompany(),
                85,
                PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION,
                12,
                [
                    'reasonCode' => 'FREEMIUM_MONTHLY_OFFER_PUBLICATION_CAP',
                    'reasonText' => 'Quota depasse.',
                    'metadata' => [],
                ],
                [
                    'offerId' => 12,
                    'impactScore' => 70,
                ],
            );

        $entityManager->expects(self::never())->method('persist');

        $entry = $service->awardOfferPublicationPoints($offer, $impactScore);

        self::assertNull($entry);
    }

    public function testGetCompanyBalanceUsesRepositoryAggregation(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createStub(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $policyService, $policyAuditService, $entityManager);

        $company = (new Company())->setName('Work Plus');
        $this->setEntityId($company, 5);

        $repository->expects(self::once())
            ->method('getCompanyBalance')
            ->with(5)
            ->willReturn(123);
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');

        self::assertSame(123, $service->getCompanyBalance($company));
    }

    public function testGetCompanySummaryReturnsBalanceAndHistory(): void
    {
        $repository = $this->createMock(PointsLedgerEntryRepository::class);
        $policyService = $this->createStub(PointsPolicyService::class);
        $policyAuditService = $this->createMock(PointsPolicyAuditService::class);
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $service = new PointsLedgerService($repository, $policyService, $policyAuditService, $entityManager);

        $company = (new Company())->setName('Work Plus');
        $this->setEntityId($company, 7);

        $historyEntry = (new PointsLedgerEntry())
            ->setEntryType(PointsLedgerEntry::TYPE_CREDIT)
            ->setReferenceType(PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION)
            ->setReason('Automatic impact points for offer publication')
            ->setPoints(40);

        $repository->expects(self::once())
            ->method('getCompanyBalance')
            ->with(7)
            ->willReturn(250);

        $repository->expects(self::once())
            ->method('findLatestForCompany')
            ->with(7, 20)
            ->willReturn([$historyEntry]);
        $policyAuditService->expects(self::never())->method('recordCompanyDecision');

        $summary = $service->getCompanySummary($company);

        self::assertSame(250, $summary['balance']);
        self::assertCount(1, $summary['history']);
        self::assertSame($historyEntry, $summary['history'][0]);
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


