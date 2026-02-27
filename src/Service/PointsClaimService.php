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
    private const EVIDENCE_MAX_AGE_MONTHS = 24;
    private const MIN_REQUIRED_DOCUMENTS = 2;
    private const REINFORCED_DOCUMENTS_COUNT = 4;
    private const STANDARD_APPROVED_POINTS = 10;
    private const REINFORCED_APPROVED_POINTS = 20;
    private const EXTERNAL_CHECKS_BONUS_POINTS = 5;
    private const EXTERNAL_CHECKS_BONUS_MIN_TRUE_CHECKS = 2;
    private const MAX_APPROVED_POINTS = 25;

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

        $evidenceScore = $this->computeEvidenceScore($coherence);
        $suggestedPoints = $this->computeSuggestedPoints($coherence, $normalizedExternalChecks);
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
        } elseif (true === ($coherence['isCoherent'] ?? false)) {
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
                    ->setDecisionReason(sprintf(
                        'Validation automatique réussie : critères requis validés. Points attribués : %d.',
                        $suggestedPoints,
                    ))
                    ->setReviewedAt(new \DateTimeImmutable());
                $this->createReviewEvent(
                    pointsClaim: $claim,
                    action: PointsClaimReviewEvent::ACTION_AUTO_APPROVED,
                    reasonCode: PointsClaim::REASON_CODE_AUTO_APPROVED_SCORE,
                    reasonText: null,
                    metadata: [
                        'requiredCriteriaCount' => count($coherence['requiredCriteria'] ?? []),
                        'approvedPoints' => $suggestedPoints,
                        'pointsBreakdown' => [
                            'base' => (int) ($coherence['basePoints'] ?? 0),
                            'externalChecksBonus' => (int) ($coherence['externalChecksBonus'] ?? 0),
                        ],
                    ],
                );
            }
        } else {
            $failedCriteria = is_array($coherence['failedRequired'] ?? null)
                ? array_values(array_filter($coherence['failedRequired'], static fn (mixed $value): bool => is_string($value)))
                : [];
            $formattedCriteria = $this->formatCriteriaForReason($failedCriteria);
            $reason = 'Validation automatique refusée : critères requis non validés.';
            if ('' !== $formattedCriteria) {
                $reason .= ' Critères manquants -> ' . $formattedCriteria . '.';
            }
            $reason .= ' Action attendue : complétez ces éléments puis renvoyez la demande.';

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
                    'requiredCriteriaCount' => count($coherence['requiredCriteria'] ?? []),
                    'criteriaPassedCount' => count($coherence['passedRequired'] ?? []),
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
     * @param array{
     *   requiredCriteria?: list<string>,
     *   passedRequired?: list<string>
     * } $coherence
     */
    private function computeEvidenceScore(array $coherence): int
    {
        $requiredCriteria = is_array($coherence['requiredCriteria'] ?? null) ? $coherence['requiredCriteria'] : [];
        $passedRequired = is_array($coherence['passedRequired'] ?? null) ? $coherence['passedRequired'] : [];
        $requiredCount = count($requiredCriteria);
        if (0 === $requiredCount) {
            return 0;
        }

        return (int) round((count($passedRequired) / $requiredCount) * 100);
    }

    /**
     * @param array{
     *   isCoherent?: bool,
     *   usableDocumentsCount?: int
     * } $coherence
     * @param array<string, mixed> $externalChecks
     */
    private function computeSuggestedPoints(array $coherence, array $externalChecks): int
    {
        if (true !== ($coherence['isCoherent'] ?? false)) {
            return 0;
        }

        $usableDocumentsCount = (int) ($coherence['usableDocumentsCount'] ?? 0);
        $basePoints = $usableDocumentsCount >= self::REINFORCED_DOCUMENTS_COUNT
            ? self::REINFORCED_APPROVED_POINTS
            : self::STANDARD_APPROVED_POINTS;

        $trueChecksCount = $this->countTrueChecks($externalChecks);
        $bonusPoints = $trueChecksCount >= self::EXTERNAL_CHECKS_BONUS_MIN_TRUE_CHECKS
            ? self::EXTERNAL_CHECKS_BONUS_POINTS
            : 0;

        $suggested = $basePoints + $bonusPoints;

        return max(0, min(self::MAX_APPROVED_POINTS, $suggested));
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
     *   requiredCriteria: list<string>,
     *   passedRequired: list<string>,
     *   usableDocumentsCount: int,
     *   basePoints: int,
     *   externalChecksBonus: int
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
        $usableDocumentsCount = $this->countUsableEvidenceDocuments($evidenceDocuments);
        $supportingDocumentsMinimum = $usableDocumentsCount >= self::MIN_REQUIRED_DOCUMENTS;
        $trueChecksCount = $this->countTrueChecks($externalChecks);
        $basePoints = $usableDocumentsCount >= self::REINFORCED_DOCUMENTS_COUNT
            ? self::REINFORCED_APPROVED_POINTS
            : self::STANDARD_APPROVED_POINTS;
        $externalChecksBonus = $trueChecksCount >= self::EXTERNAL_CHECKS_BONUS_MIN_TRUE_CHECKS
            ? self::EXTERNAL_CHECKS_BONUS_POINTS
            : 0;

        $criteria = [
            'profile_complete' => $profileComplete,
            'offer_consistency' => $offerConsistency,
            'evidence_date_valid' => $evidenceDateValid,
            'supporting_documents_minimum' => $supportingDocumentsMinimum,
        ];
        $requiredCriteria = array_keys($criteria);
        $passedRequired = array_values(array_filter(
            $requiredCriteria,
            static fn (string $key): bool => true === $criteria[$key],
        ));
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
            'passedRequired' => $passedRequired,
            'usableDocumentsCount' => $usableDocumentsCount,
            'basePoints' => $basePoints,
            'externalChecksBonus' => $externalChecksBonus,
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
    private function countUsableEvidenceDocuments(array $evidenceDocuments): int
    {
        $count = 0;
        foreach ($evidenceDocuments as $document) {
            $isValid = true === ($document['valid'] ?? false);
            $fileHash = trim((string) ($document['fileHash'] ?? ''));
            $size = (int) ($document['size'] ?? 0);

            if ($isValid && ('' !== $fileHash || $size > 0)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $externalChecks
     */
    private function countTrueChecks(array $externalChecks): int
    {
        $checks = is_array($externalChecks['checks'] ?? null) ? $externalChecks['checks'] : [];
        $count = 0;
        foreach ($checks as $value) {
            if (true === $value) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<string> $failedCriteria
     */
    private function formatCriteriaForReason(array $failedCriteria): string
    {
        if ([] === $failedCriteria) {
            return '';
        }

        $labels = [
            'profile_complete' => 'profil entreprise complet',
            'offer_consistency' => 'offre cohérente',
            'evidence_date_valid' => 'date de preuve valide',
            'supporting_documents_minimum' => sprintf('au moins %d justificatifs exploitables', self::MIN_REQUIRED_DOCUMENTS),
        ];

        $parts = [];
        foreach ($failedCriteria as $code) {
            $parts[] = $labels[$code] ?? $code;
        }

        return implode(', ', $parts);
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
