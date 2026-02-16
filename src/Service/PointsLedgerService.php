<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\ImpactScore;
use App\Entity\Offer;
use App\Entity\PointsLedgerEntry;
use App\Repository\PointsLedgerEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

class PointsLedgerService
{
    public function __construct(
        private readonly PointsLedgerEntryRepository $pointsLedgerEntryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function awardOfferPublicationPoints(Offer $offer, ImpactScore $impactScore): ?PointsLedgerEntry
    {
        $offerId = $offer->getId();
        $company = $offer->getCompany();

        if (null === $offerId || !$company instanceof Company) {
            return null;
        }

        $idempotencyKey = sprintf('offer_publication_company_%d', $offerId);
        if ($this->pointsLedgerEntryRepository->existsByIdempotencyKey($idempotencyKey)) {
            return null;
        }

        $points = $this->computePublicationPoints($impactScore);
        if ($points <= 0) {
            return null;
        }

        $entry = (new PointsLedgerEntry())
            ->setEntryType(PointsLedgerEntry::TYPE_CREDIT)
            ->setPoints($points)
            ->setReason('Automatic impact points for offer publication')
            ->setReferenceType(PointsLedgerEntry::REFERENCE_OFFER_PUBLICATION)
            ->setReferenceId($offerId)
            ->setRuleVersion($impactScore->getRuleVersion())
            ->setIdempotencyKey($idempotencyKey)
            ->setCompany($company)
            ->setMetadata([
                'offerId' => $offerId,
                'impactScore' => $impactScore->getTotalScore(),
                'confidence' => $impactScore->getConfidence(),
                'societyScore' => $impactScore->getSocietyScore(),
                'biodiversityScore' => $impactScore->getBiodiversityScore(),
                'ghgScore' => $impactScore->getGhgScore(),
            ]);

        $this->entityManager->persist($entry);

        return $entry;
    }

    public function getCompanyBalance(Company $company): int
    {
        $companyId = $company->getId();
        if (null === $companyId) {
            return 0;
        }

        return $this->pointsLedgerEntryRepository->getCompanyBalance($companyId);
    }

    private function computePublicationPoints(ImpactScore $impactScore): int
    {
        $points = $impactScore->getTotalScore();

        if (
            $impactScore->getSocietyScore() >= 40
            && $impactScore->getBiodiversityScore() >= 40
            && $impactScore->getGhgScore() >= 40
        ) {
            $points += 10;
        }

        if ($impactScore->getConfidence() >= 0.85) {
            $points += 5;
        }

        return max(0, min(120, $points));
    }
}
