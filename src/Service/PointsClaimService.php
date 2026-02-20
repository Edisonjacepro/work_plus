<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\Offer;
use App\Entity\PointsClaim;
use App\Entity\PointsClaimReviewEvent;
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
        ?\DateTimeImmutable $evidenceIssuedAt = null,
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
        $companyId = $company->getId();
        if (null === $companyId) {
            throw new \InvalidArgumentException('Company id is required.');
        }

        $duplicateHash = $this->findDuplicateEvidenceHash($companyId, $evidenceDocuments);
        $isEvidenceTooOld = $this->isEvidenceTooOld($evidenceIssuedAt);

        $claim = (new PointsClaim())
            ->setCompany($company)
            ->setOffer($offer)
            ->setClaimType($claimType)
            ->setRequestedPoints($suggestedPoints)
            ->setEvidenceDocuments($evidenceDocuments)
            ->setExternalChecks($externalChecks)
            ->setEvidenceScore($evidenceScore)
            ->setEvidenceIssuedAt($evidenceIssuedAt)
            ->setRuleVersion(PointsClaim::RULE_VERSION_V1)
            ->setIdempotencyKey($normalizedKey);

        $this->entityManager->persist($claim);
        $this->createReviewEvent(
            pointsClaim: $claim,
            action: PointsClaimReviewEvent::ACTION_SUBMITTED,
            reasonCode: null,
            reasonText: null,
            metadata: [
                'evidenceScore' => $evidenceScore,
                'suggestedPoints' => $suggestedPoints,
            ],
        );

        if (null !== $duplicateHash) {
            $claim
                ->setStatus(PointsClaim::STATUS_REJECTED)
                ->setDecisionReasonCode(PointsClaim::REASON_CODE_DUPLICATE_EVIDENCE_FILE)
                ->setDecisionReason('Un justificatif identique a deja ete soumis.')
                ->setReviewedAt(new \DateTimeImmutable());
            $this->createReviewEvent(
                pointsClaim: $claim,
                action: PointsClaimReviewEvent::ACTION_AUTO_REJECTED,
                reasonCode: PointsClaim::REASON_CODE_DUPLICATE_EVIDENCE_FILE,
                reasonText: null,
                metadata: ['duplicateHash' => $duplicateHash],
            );
        } elseif ($isEvidenceTooOld) {
            $claim
                ->setStatus(PointsClaim::STATUS_REJECTED)
                ->setDecisionReasonCode(PointsClaim::REASON_CODE_EVIDENCE_TOO_OLD)
                ->setDecisionReason('La date de preuve est trop ancienne.')
                ->setReviewedAt(new \DateTimeImmutable());
            $this->createReviewEvent(
                pointsClaim: $claim,
                action: PointsClaimReviewEvent::ACTION_AUTO_REJECTED,
                reasonCode: PointsClaim::REASON_CODE_EVIDENCE_TOO_OLD,
                reasonText: null,
                metadata: [
                    'evidenceIssuedAt' => $evidenceIssuedAt?->format('Y-m-d'),
                ],
            );
        } elseif ($evidenceScore >= 70) {
            $claim
                ->setStatus(PointsClaim::STATUS_APPROVED)
                ->setApprovedPoints($suggestedPoints)
                ->setDecisionReasonCode(PointsClaim::REASON_CODE_AUTO_APPROVED_SCORE)
                ->setDecisionReason('Validation automatique par score.')
                ->setReviewedAt(new \DateTimeImmutable());
            $this->createReviewEvent(
                pointsClaim: $claim,
                action: PointsClaimReviewEvent::ACTION_AUTO_APPROVED,
                reasonCode: PointsClaim::REASON_CODE_AUTO_APPROVED_SCORE,
                reasonText: null,
                metadata: null,
            );
        } else {
            $claim
                ->setStatus(PointsClaim::STATUS_REJECTED)
                ->setDecisionReasonCode(PointsClaim::REASON_CODE_INSUFFICIENT_EVIDENCE_SCORE)
                ->setDecisionReason('Score de preuve insuffisant pour validation automatique.')
                ->setReviewedAt(new \DateTimeImmutable());
            $this->createReviewEvent(
                pointsClaim: $claim,
                action: PointsClaimReviewEvent::ACTION_AUTO_REJECTED,
                reasonCode: PointsClaim::REASON_CODE_INSUFFICIENT_EVIDENCE_SCORE,
                reasonText: null,
                metadata: null,
            );
        }

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
        throw new \LogicException('Manual review is disabled in v1 automatic mode.');
    }

    public function approve(
        PointsClaim $claim,
        User $reviewer,
        string $reasonCode,
        ?int $approvedPoints = null,
        ?string $reasonNote = null,
    ): ?PointsLedgerEntry
    {
        throw new \LogicException('Manual review is disabled in v1 automatic mode.');
    }

    public function reject(PointsClaim $claim, User $reviewer, string $reasonCode, ?string $reasonNote = null): void
    {
        throw new \LogicException('Manual review is disabled in v1 automatic mode.');
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

    /**
     * @param list<array<string, mixed>> $evidenceDocuments
     */
    private function findDuplicateEvidenceHash(int $companyId, array $evidenceDocuments): ?string
    {
        foreach ($evidenceDocuments as $document) {
            $fileHash = (string) ($document['fileHash'] ?? '');
            if ('' === $fileHash) {
                continue;
            }

            if ($this->pointsClaimRepository->hasEvidenceHashForCompany($companyId, $fileHash)) {
                return $fileHash;
            }
        }

        return null;
    }

    private function isEvidenceTooOld(?\DateTimeImmutable $evidenceIssuedAt): bool
    {
        if (!$evidenceIssuedAt instanceof \DateTimeImmutable) {
            return false;
        }

        $threshold = (new \DateTimeImmutable('today'))->modify('-24 months');

        return $evidenceIssuedAt < $threshold;
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

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function createReviewEvent(
        PointsClaim $pointsClaim,
        string $action,
        ?User $actor = null,
        ?string $reasonCode = null,
        ?string $reasonText = null,
        ?array $metadata = null,
    ): void {
        $event = (new PointsClaimReviewEvent())
            ->setPointsClaim($pointsClaim)
            ->setActor($actor)
            ->setAction($action)
            ->setReasonCode($reasonCode)
            ->setReasonText($reasonText)
            ->setMetadata($metadata);

        $this->entityManager->persist($event);
    }
}
