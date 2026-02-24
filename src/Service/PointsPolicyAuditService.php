<?php

namespace App\Service;

use App\Entity\Company;
use App\Entity\PointsPolicyDecision;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class PointsPolicyAuditService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array{reasonCode: string, reasonText: string, metadata: array<string, mixed>}|null $policyDecision
     * @param array<string, mixed>|null $metadata
     */
    public function recordCompanyDecision(
        Company $company,
        int $points,
        string $referenceType,
        ?int $referenceId,
        ?array $policyDecision,
        ?array $metadata = null,
    ): void {
        $decision = $this->buildDecision(
            points: $points,
            referenceType: $referenceType,
            referenceId: $referenceId,
            policyDecision: $policyDecision,
            metadata: $metadata,
        )->setCompany($company);

        $this->entityManager->persist($decision);
    }

    /**
     * @param array{reasonCode: string, reasonText: string, metadata: array<string, mixed>}|null $policyDecision
     * @param array<string, mixed>|null $metadata
     */
    public function recordUserDecision(
        User $user,
        int $points,
        string $referenceType,
        ?int $referenceId,
        ?array $policyDecision,
        ?array $metadata = null,
    ): void {
        $decision = $this->buildDecision(
            points: $points,
            referenceType: $referenceType,
            referenceId: $referenceId,
            policyDecision: $policyDecision,
            metadata: $metadata,
        )->setUser($user);

        $this->entityManager->persist($decision);
    }

    /**
     * @param array{reasonCode: string, reasonText: string, metadata: array<string, mixed>}|null $policyDecision
     * @param array<string, mixed>|null $metadata
     */
    private function buildDecision(
        int $points,
        string $referenceType,
        ?int $referenceId,
        ?array $policyDecision,
        ?array $metadata,
    ): PointsPolicyDecision {
        $status = is_array($policyDecision) ? PointsPolicyDecision::STATUS_BLOCK : PointsPolicyDecision::STATUS_ALLOW;
        $reasonCode = is_array($policyDecision)
            ? (string) ($policyDecision['reasonCode'] ?? PointsPolicyDecision::REASON_CODE_ALLOWED)
            : PointsPolicyDecision::REASON_CODE_ALLOWED;
        $reasonText = is_array($policyDecision) ? (string) ($policyDecision['reasonText'] ?? '') : null;
        if ('' === $reasonText) {
            $reasonText = null;
        }

        $policyMetadata = is_array($policyDecision['metadata'] ?? null) ? $policyDecision['metadata'] : [];
        $mergedMetadata = $policyMetadata;
        if (is_array($metadata)) {
            $mergedMetadata = array_merge($policyMetadata, $metadata);
        }

        $ruleVersion = (string) ($policyMetadata['ruleVersion'] ?? PointsPolicyService::RULE_VERSION);
        if ('' === $ruleVersion) {
            $ruleVersion = PointsPolicyService::RULE_VERSION;
        }

        return (new PointsPolicyDecision())
            ->setDecisionStatus($status)
            ->setReasonCode($reasonCode)
            ->setReasonText($reasonText)
            ->setReferenceType($referenceType)
            ->setReferenceId($referenceId)
            ->setPoints($points)
            ->setRuleVersion($ruleVersion)
            ->setMetadata([] === $mergedMetadata ? null : $mergedMetadata);
    }
}
