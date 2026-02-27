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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

class PointsClaimService
{
    private const AUTO_APPROVAL_THRESHOLD = 70;
    private const EVIDENCE_MAX_AGE_MONTHS = 24;

    public function __construct(
        private readonly PointsClaimRepository $pointsClaimRepository,
        private readonly PointsLedgerEntryRepository $pointsLedgerEntryRepository,
        private readonly PointsPolicyService $pointsPolicyService,
        private readonly PointsPolicyAuditService $pointsPolicyAuditService,
        private readonly PointsPolicyRiskService $pointsPolicyRiskService,
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

        $normalizedExternalChecks = is_array($externalChecks) ? $externalChecks : [];
        $coherence = $this->evaluateCoherence($evidenceDocuments, $normalizedExternalChecks, $evidenceIssuedAt);
        $normalizedExternalChecks['coherence'] = $coherence;

        $evidenceScore = $this->computeEvidenceScore($evidenceDocuments, $normalizedExternalChecks);
        $suggestedPoints = $this->computeSuggestedPoints($evidenceScore);
        $companyId = $company->getId();
        if (null === $companyId) {
            throw new \InvalidArgumentException('Company id is required.');
        }

        $claim = (new PointsClaim())
            ->setCompany($company)
            ->setOffer($offer)
            ->setClaimType($claimType)
            ->setRequestedPoints($suggestedPoints)
            ->setEvidenceDocuments($evidenceDocuments)
            ->setExternalChecks($normalizedExternalChecks)
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

        $riskSummary = $this->pointsPolicyRiskService->getCompanyRiskSummary($company);
        if (true === $riskSummary['cooldownActive']) {
            $cooldownUntil = $riskSummary['cooldownUntil'];
            $reasonText = 'Pause de sécurité activée après plusieurs refus automatiques récents.';

            $this->pointsPolicyAuditService->recordCompanyDecision(
                company: $company,
                points: $suggestedPoints,
                referenceType: PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                referenceId: $claim->getId(),
                policyDecision: [
                    'reasonCode' => 'COMPANY_COOLDOWN_ACTIVE',
                    'reasonText' => $reasonText,
                    'metadata' => [
                        'ruleVersion' => PointsPolicyService::RULE_VERSION,
                        'blocked24h' => $riskSummary['blocked24h'],
                        'blocked7d' => $riskSummary['blocked7d'],
                        'threshold24h' => $riskSummary['threshold24h'],
                        'cooldownUntil' => $cooldownUntil?->format(DATE_ATOM),
                    ],
                ],
                metadata: [
                    'claimType' => $claimType,
                    'claimIdempotencyKey' => $normalizedKey,
                ],
            );

            $claim
                ->setStatus(PointsClaim::STATUS_REJECTED)
                ->setDecisionReasonCode(PointsClaim::REASON_CODE_COOLDOWN_ACTIVE)
                ->setDecisionReason($reasonText)
                ->setReviewedAt(new \DateTimeImmutable());
            $this->createReviewEvent(
                pointsClaim: $claim,
                action: PointsClaimReviewEvent::ACTION_AUTO_REJECTED,
                reasonCode: PointsClaim::REASON_CODE_COOLDOWN_ACTIVE,
                reasonText: null,
                metadata: [
                    'blocked24h' => $riskSummary['blocked24h'],
                    'blocked7d' => $riskSummary['blocked7d'],
                    'threshold24h' => $riskSummary['threshold24h'],
                    'cooldownUntil' => $cooldownUntil?->format(DATE_ATOM),
                ],
            );

            return $claim;
        }

        $duplicateHash = $this->findDuplicateEvidenceHash($companyId, $evidenceDocuments);
        $isEvidenceTooOld = $this->isEvidenceTooOld($evidenceIssuedAt);

        if (null !== $duplicateHash) {
            $claim
                ->setStatus(PointsClaim::STATUS_REJECTED)
                ->setDecisionReasonCode(PointsClaim::REASON_CODE_DUPLICATE_EVIDENCE_FILE)
                ->setDecisionReason('Un justificatif identique a déjà été soumis.')
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
        } elseif ($evidenceScore >= self::AUTO_APPROVAL_THRESHOLD) {
            $policyDecision = $this->pointsPolicyService->evaluateCompanyCredit(
                company: $company,
                points: $suggestedPoints,
                referenceType: PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
            );
            $this->pointsPolicyAuditService->recordCompanyDecision(
                company: $company,
                points: $suggestedPoints,
                referenceType: PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                referenceId: $claim->getId(),
                policyDecision: $policyDecision,
                metadata: [
                    'claimType' => $claimType,
                    'claimIdempotencyKey' => $normalizedKey,
                ],
            );
            if (is_array($policyDecision)) {
                $claim
                    ->setStatus(PointsClaim::STATUS_REJECTED)
                    ->setDecisionReasonCode($policyDecision['reasonCode'])
                    ->setDecisionReason($policyDecision['reasonText'])
                    ->setReviewedAt(new \DateTimeImmutable());
                $this->createReviewEvent(
                    pointsClaim: $claim,
                    action: PointsClaimReviewEvent::ACTION_AUTO_REJECTED,
                    reasonCode: $policyDecision['reasonCode'],
                    reasonText: null,
                    metadata: $policyDecision['metadata'],
                );
            } else {
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
            }
        } else {
            $missingScore = max(0, self::AUTO_APPROVAL_THRESHOLD - $evidenceScore);
            $failedCriteria = is_array($coherence['failedRequired'] ?? null)
                ? array_values(array_filter($coherence['failedRequired'], static fn (mixed $value): bool => is_string($value)))
                : [];
            $reason = sprintf(
                'Validation automatique refusée : score %d/100 (seuil %d, manque %d).',
                $evidenceScore,
                self::AUTO_APPROVAL_THRESHOLD,
                $missingScore,
            );
            if ([] !== $failedCriteria) {
                $reason .= ' Cohérence non validée : ' . implode(', ', $failedCriteria) . '.';
            }
            $reason .= ' Ajoutez des justificatifs exploitables et des informations cohérentes.';

            $claim
                ->setStatus(PointsClaim::STATUS_REJECTED)
                ->setDecisionReasonCode(PointsClaim::REASON_CODE_INSUFFICIENT_EVIDENCE_SCORE)
                ->setDecisionReason($reason)
                ->setReviewedAt(new \DateTimeImmutable());
            $this->createReviewEvent(
                pointsClaim: $claim,
                action: PointsClaimReviewEvent::ACTION_AUTO_REJECTED,
                reasonCode: PointsClaim::REASON_CODE_INSUFFICIENT_EVIDENCE_SCORE,
                reasonText: null,
                metadata: [
                    'score' => $evidenceScore,
                    'threshold' => self::AUTO_APPROVAL_THRESHOLD,
                    'missing' => $missingScore,
                    'failedRequiredCoherenceCriteria' => $failedCriteria,
                ],
            );
        }

        if (PointsClaim::STATUS_APPROVED === $claim->getStatus()) {
            if (null === $claim->getId()) {
                $this->entityManager->flush();
            }
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

        $coherenceData = is_array($externalChecks['coherence'] ?? null) ? $externalChecks['coherence'] : [];
        $coherenceScore = true === ($coherenceData['isCoherent'] ?? ($externalChecks['coherenceOk'] ?? null)) ? 20 : 0;

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

        $threshold = (new \DateTimeImmutable('today'))->modify('-' . self::EVIDENCE_MAX_AGE_MONTHS . ' months');

        return $evidenceIssuedAt < $threshold;
    }

    /**
     * @param list<array<string, mixed>> $evidenceDocuments
     * @param array<string, mixed> $externalChecks
     * @return array{
     *   isCoherent: bool,
     *   criteria: array<string, bool>,
     *   failedRequired: list<string>,
     *   requiredCriteria: list<string>
     * }
     */
    private function evaluateCoherence(
        array $evidenceDocuments,
        array $externalChecks,
        ?\DateTimeImmutable $evidenceIssuedAt,
    ): array {
        $checks = is_array($externalChecks['checks'] ?? null) ? $externalChecks['checks'] : [];
        $legacyCoherenceOk = true === ($externalChecks['coherenceOk'] ?? null);

        $hasCompanyWebsite = $checks['hasCompanyWebsite'] ?? null;
        $hasCompanyCity = $checks['hasCompanyCity'] ?? null;
        $hasCompanySector = $checks['hasCompanySector'] ?? null;
        $offerLinked = $checks['offerLinked'] ?? null;
        $sameCompanyOffer = $checks['sameCompanyOffer'] ?? null;

        $profileComplete = is_bool($hasCompanyWebsite) && is_bool($hasCompanyCity) && is_bool($hasCompanySector)
            ? ($hasCompanyWebsite && $hasCompanyCity && $hasCompanySector)
            : $legacyCoherenceOk;
        $offerConsistency = is_bool($offerLinked)
            ? (false === $offerLinked || true === $sameCompanyOffer)
            : $legacyCoherenceOk;
        $evidenceDateValid = $this->isEvidenceDateValid($evidenceIssuedAt);
        $hasUsableEvidenceDocument = $this->hasUsableEvidenceDocument($evidenceDocuments);

        $criteria = [
            'profile_complete' => $profileComplete,
            'offer_consistency' => $offerConsistency,
            'evidence_date_valid' => $evidenceDateValid,
            'has_usable_document' => $hasUsableEvidenceDocument,
        ];
        $requiredCriteria = array_keys($criteria);
        $failedRequired = array_values(array_filter(
            $requiredCriteria,
            static fn (string $key): bool => true !== $criteria[$key],
        ));
        $isCoherent = [] === $failedRequired;

        return [
            'isCoherent' => $isCoherent,
            'criteria' => $criteria,
            'failedRequired' => $failedRequired,
            'requiredCriteria' => $requiredCriteria,
        ];
    }

    private function isEvidenceDateValid(?\DateTimeImmutable $evidenceIssuedAt): bool
    {
        if (!$evidenceIssuedAt instanceof \DateTimeImmutable) {
            return false;
        }

        $today = new \DateTimeImmutable('today');
        if ($evidenceIssuedAt > $today) {
            return false;
        }

        return !$this->isEvidenceTooOld($evidenceIssuedAt);
    }

    /**
     * @param list<array<string, mixed>> $evidenceDocuments
     */
    private function hasUsableEvidenceDocument(array $evidenceDocuments): bool
    {
        foreach ($evidenceDocuments as $document) {
            $isValid = true === ($document['valid'] ?? false);
            $fileHash = trim((string) ($document['fileHash'] ?? ''));
            $size = (int) ($document['size'] ?? 0);

            if ($isValid && ('' !== $fileHash || $size > 0)) {
                return true;
            }
        }

        return false;
    }

    private function createApprovalLedgerEntry(PointsClaim $claim, int $points): void
    {
        $claimId = $claim->getId();
        if (null === $claimId) {
            throw new \LogicException('Points claim id must be available before creating approval ledger entry.');
        }

        $idempotencyKey = sprintf('points_claim_approval_%s', $claim->getIdempotencyKey());
        if ($this->pointsLedgerEntryRepository->existsByIdempotencyKey($idempotencyKey)) {
            return;
        }

        $metadata = [
            'claimType' => $claim->getClaimType(),
            'evidenceScore' => $claim->getEvidenceScore(),
            'companyId' => $claim->getCompany()?->getId(),
            'offerId' => $claim->getOffer()?->getId(),
        ];

        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO points_ledger_entry (
                    company_id,
                    user_id,
                    entry_type,
                    points,
                    reason,
                    reference_type,
                    reference_id,
                    rule_version,
                    idempotency_key,
                    metadata,
                    created_at
                ) VALUES (
                    :companyId,
                    NULL,
                    :entryType,
                    :points,
                    :reason,
                    :referenceType,
                    :referenceId,
                    :ruleVersion,
                    :idempotencyKey,
                    :metadata,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT DO NOTHING
                SQL,
            [
                'companyId' => $claim->getCompany()?->getId(),
                'entryType' => PointsLedgerEntry::TYPE_CREDIT,
                'points' => $points,
                'reason' => 'Points claim approved',
                'referenceType' => PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
                'referenceId' => $claimId,
                'ruleVersion' => $claim->getRuleVersion(),
                'idempotencyKey' => $idempotencyKey,
                'metadata' => $metadata,
            ],
            [
                'companyId' => Types::INTEGER,
                'entryType' => Types::STRING,
                'points' => Types::INTEGER,
                'reason' => Types::STRING,
                'referenceType' => Types::STRING,
                'referenceId' => Types::INTEGER,
                'ruleVersion' => Types::STRING,
                'idempotencyKey' => Types::STRING,
                'metadata' => Types::JSON,
            ],
        );

        return;
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
