<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Offer;
use App\Entity\PointsClaim;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsClaimRepository;
use App\Repository\PointsLedgerEntryRepository;
use Doctrine\ORM\EntityManagerInterface;

class PointsClaimService
{
    public function __construct(
        private readonly PointsClaimRepository $pointsClaimRepository,
        private readonly PointsLedgerEntryRepository $pointsLedgerEntryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $evidenceDocuments
     * @param array<string, mixed>|null $externalChecks
     */
    public function submit(
        Company $company,
        string $claimType,
        array $evidenceDocuments,
        string $idempotencyKey,
        ?Offer $offer = null,
        ?array $externalChecks = null,
    ): PointsClaim {
        $normalizedKey = trim($idempotencyKey);
        if ('' === $normalizedKey) {
            throw new \InvalidArgumentException('Idempotency key is required.');
        }

        $existing = $this->pointsClaimRepository->findOneByIdempotencyKey($normalizedKey);
        if ($existing instanceof PointsClaim) {
            return $existing;
        }

        if ([] === $evidenceDocuments) {
            throw new \InvalidArgumentException('At least one supporting document is required.');
        }

        $evidenceScore = $this->computeEvidenceScore($evidenceDocuments, $externalChecks);
        $suggestedPoints = $this->computeSuggestedPoints($evidenceScore);

        $claim = (new PointsClaim())
            ->setCompany($company)
            ->setOffer($offer)
            ->setClaimType($claimType)
            ->setRequestedPoints($suggestedPoints)
            ->setEvidenceDocuments($evidenceDocuments)
            ->setExternalChecks($externalChecks)
            ->setEvidenceScore($evidenceScore)
            ->setRuleVersion(PointsClaim::RULE_VERSION_V1)
            ->setIdempotencyKey($normalizedKey);

        if ($evidenceScore >= 70) {
            $claim
                ->setStatus(PointsClaim::STATUS_APPROVED)
                ->setApprovedPoints($suggestedPoints)
                ->setDecisionReason('AUTO_APPROVED_EVIDENCE_SCORE')
                ->setReviewedAt(new \DateTimeImmutable());
        } elseif ($evidenceScore >= 40) {
            $claim->setStatus(PointsClaim::STATUS_IN_REVIEW);
        } else {
            $claim
                ->setStatus(PointsClaim::STATUS_REJECTED)
                ->setDecisionReason('INSUFFICIENT_EVIDENCE_SCORE')
                ->setReviewedAt(new \DateTimeImmutable());
        }

        $this->entityManager->persist($claim);

        if (PointsClaim::STATUS_APPROVED === $claim->getStatus()) {
            $this->createApprovalLedgerEntry($claim, $suggestedPoints);
        }

        return $claim;
    }

    /**
     * @param array<string, mixed>|null $externalChecks
     */
    public function markInReview(PointsClaim $claim, ?array $externalChecks = null): void
    {
        if (PointsClaim::STATUS_APPROVED === $claim->getStatus() || PointsClaim::STATUS_REJECTED === $claim->getStatus()) {
            throw new \LogicException('Cannot move an approved or rejected claim back to in review.');
        }

        $claim
            ->setStatus(PointsClaim::STATUS_IN_REVIEW)
            ->setExternalChecks($externalChecks);

        $this->entityManager->persist($claim);
    }

    public function approve(PointsClaim $claim, User $reviewer, ?int $approvedPoints = null, ?string $reason = null): ?PointsLedgerEntry
    {
        if (PointsClaim::STATUS_REJECTED === $claim->getStatus()) {
            throw new \LogicException('A rejected claim cannot be approved directly.');
        }

        $points = $approvedPoints ?? $claim->getRequestedPoints();
        if ($points <= 0) {
            throw new \InvalidArgumentException('Approved points must be greater than zero.');
        }

        $claim
            ->setStatus(PointsClaim::STATUS_APPROVED)
            ->setApprovedPoints($points)
            ->setDecisionReason(null !== $reason ? trim($reason) : 'APPROVED_BY_REVIEWER')
            ->setReviewedBy($reviewer)
            ->setReviewedAt(new \DateTimeImmutable());

        $this->entityManager->persist($claim);

        return $this->createApprovalLedgerEntry($claim, $points);
    }

    public function reject(PointsClaim $claim, User $reviewer, string $reason): void
    {
        $trimmedReason = trim($reason);
        if ('' === $trimmedReason) {
            throw new \InvalidArgumentException('A rejection reason is required.');
        }

        if (PointsClaim::STATUS_APPROVED === $claim->getStatus()) {
            throw new \LogicException('An approved claim cannot be rejected directly.');
        }

        $claim
            ->setStatus(PointsClaim::STATUS_REJECTED)
            ->setDecisionReason($trimmedReason)
            ->setReviewedBy($reviewer)
            ->setReviewedAt(new \DateTimeImmutable());

        $this->entityManager->persist($claim);
    }

    /**
     * @param list<array<string, mixed>> $evidenceDocuments
     * @param array<string, mixed>|null $externalChecks
     */
    private function computeEvidenceScore(array $evidenceDocuments, ?array $externalChecks): int
    {
        $documentCount = count($evidenceDocuments);

        $completenessScore = min(40, $documentCount * 10);

        $validDocuments = 0;
        foreach ($evidenceDocuments as $document) {
            if (true === ($document['valid'] ?? false)) {
                ++$validDocuments;
            }
        }
        $technicalScore = min(20, $validDocuments * 5);

        $coherenceScore = true === ($externalChecks['coherenceOk'] ?? null) ? 20 : 0;

        $apiScore = 0;
        $checks = is_array($externalChecks['checks'] ?? null) ? $externalChecks['checks'] : [];
        foreach ($checks as $value) {
            if (true === $value) {
                $apiScore += 5;
            }
        }
        $apiScore = min(20, $apiScore);

        $score = $completenessScore + $technicalScore + $coherenceScore + $apiScore;

        return max(0, min(100, $score));
    }

    private function computeSuggestedPoints(int $evidenceScore): int
    {
        $suggested = (int) round($evidenceScore * 0.25);

        return max(5, min(30, $suggested));
    }

    private function createApprovalLedgerEntry(PointsClaim $claim, int $points): ?PointsLedgerEntry
    {
        $idempotencyKey = sprintf('points_claim_approval_%s', $claim->getIdempotencyKey());
        if ($this->pointsLedgerEntryRepository->existsByIdempotencyKey($idempotencyKey)) {
            return null;
        }

        $entry = (new PointsLedgerEntry())
            ->setEntryType(PointsLedgerEntry::TYPE_CREDIT)
            ->setPoints($points)
            ->setReason('Points claim approved')
            ->setReferenceType(PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL)
            ->setReferenceId($claim->getId())
            ->setRuleVersion($claim->getRuleVersion())
            ->setIdempotencyKey($idempotencyKey)
            ->setCompany($claim->getCompany())
            ->setMetadata([
                'claimType' => $claim->getClaimType(),
                'evidenceScore' => $claim->getEvidenceScore(),
                'companyId' => $claim->getCompany()?->getId(),
                'offerId' => $claim->getOffer()?->getId(),
            ]);

        $this->entityManager->persist($entry);

        return $entry;
    }
}
