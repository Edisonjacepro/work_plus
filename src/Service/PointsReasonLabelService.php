<?php

namespace App\Service;

use App\Entity\Offer;
use App\Entity\PointsClaim;
use App\Entity\PointsClaimReviewEvent;
use App\Entity\PointsPolicyDecision;
use App\Entity\User;

class PointsReasonLabelService
{
    /**
     * @var array<string, string>
     */
    private const POINTS_CLAIM_REASON_LABELS = [
        PointsClaim::REASON_CODE_AUTO_APPROVED_SCORE => 'Validation automatique réussie',
        PointsClaim::REASON_CODE_INSUFFICIENT_EVIDENCE_SCORE => 'Preuves insuffisantes pour validation automatique',
        PointsClaim::REASON_CODE_DUPLICATE_EVIDENCE_FILE => 'Justificatif déjà utilisé',
        PointsClaim::REASON_CODE_EVIDENCE_TOO_OLD => 'Date de preuve trop ancienne',
        PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_POINTS_CAP => 'Plafond journalier de points atteint',
        PointsClaim::REASON_CODE_ANTI_FRAUD_DAILY_CLAIMS_CAP => 'Plafond journalier de demandes atteint',
        PointsClaim::REASON_CODE_ANTI_FRAUD_MONTHLY_POINTS_CAP => 'Plafond mensuel de points atteint',
        PointsClaim::REASON_CODE_FREEMIUM_MONTHLY_CLAIMS_QUOTA => 'Quota mensuel de demandes freemium atteint',
        PointsClaim::REASON_CODE_COOLDOWN_ACTIVE => 'Pause de sécurité activée',
        PointsClaim::REASON_CODE_APPROVED_BY_REVIEWER => 'Validé par un relecteur',
        PointsClaim::REASON_CODE_REJECTED_BY_REVIEWER => 'Rejeté par un relecteur',
        'COMPANY_COOLDOWN_ACTIVE' => 'Pause de sécurité activée',
        'COMPANY_DAILY_POINTS_CAP' => 'Plafond journalier entreprise atteint',
        'COMPANY_DAILY_CREDITS_CAP' => 'Plafond journalier de crédits entreprise atteint',
        'COMPANY_MONTHLY_POINTS_CAP' => 'Plafond mensuel entreprise atteint',
        'FREEMIUM_MONTHLY_OFFER_PUBLICATION_CAP' => 'Quota mensuel freemium des offres atteint',
        'USER_DAILY_POINTS_CAP' => 'Plafond journalier candidat atteint',
        'USER_DAILY_CREDITS_CAP' => 'Plafond journalier de crédits candidat atteint',
        'USER_MONTHLY_POINTS_CAP' => 'Plafond mensuel candidat atteint',
        PointsPolicyDecision::REASON_CODE_ALLOWED => 'Règle validée',
    ];

    /**
     * @var array<string, string>
     */
    private const REVIEW_ACTION_LABELS = [
        PointsClaimReviewEvent::ACTION_SUBMITTED => 'Soumise',
        PointsClaimReviewEvent::ACTION_AUTO_APPROVED => 'Validée automatiquement',
        PointsClaimReviewEvent::ACTION_AUTO_REJECTED => 'Rejetée automatiquement',
        PointsClaimReviewEvent::ACTION_MARKED_IN_REVIEW => 'En cours de revue',
        PointsClaimReviewEvent::ACTION_APPROVED => 'Validée par un relecteur',
        PointsClaimReviewEvent::ACTION_REJECTED => 'Rejetée par un relecteur',
    ];

    /**
     * @var array<string, string>
     */
    private const POLICY_STATUS_LABELS = [
        PointsPolicyDecision::STATUS_ALLOW => 'Autorisé',
        PointsPolicyDecision::STATUS_BLOCK => 'Bloqué',
    ];

    /**
     * @var array<string, string>
     */
    private const POINTS_CLAIM_STATUS_LABELS = [
        PointsClaim::STATUS_SUBMITTED => 'Soumise',
        PointsClaim::STATUS_APPROVED => 'Approuvée',
        PointsClaim::STATUS_REJECTED => 'Rejetée',
    ];

    /**
     * @var array<string, string>
     */
    private const POINTS_CLAIM_TYPE_LABELS = [
        PointsClaim::CLAIM_TYPE_TRAINING => 'Formation',
        PointsClaim::CLAIM_TYPE_VOLUNTEERING => 'Bénévolat',
        PointsClaim::CLAIM_TYPE_CERTIFICATION => 'Certification',
        PointsClaim::CLAIM_TYPE_OTHER => 'Autre',
    ];

    /**
     * @var array<string, string>
     */
    private const OFFER_STATUS_LABELS = [
        Offer::STATUS_DRAFT => 'Brouillon',
        Offer::STATUS_PUBLISHED => 'Publiée',
    ];

    /**
     * @var array<string, string>
     */
    private const OFFER_MODERATION_STATUS_LABELS = [
        Offer::MODERATION_STATUS_DRAFT => 'Brouillon',
        Offer::MODERATION_STATUS_SUBMITTED => 'Soumise',
        Offer::MODERATION_STATUS_APPROVED => 'Approuvée automatiquement',
        Offer::MODERATION_STATUS_REJECTED => 'Rejetée automatiquement',
    ];

    /**
     * @var array<string, string>
     */
    private const OFFER_MODERATION_REASON_LABELS = [
        ImpactEligibilityService::REASON_CODE_ELIGIBLE => 'Annonce éligible',
        ImpactEligibilityService::REASON_CODE_MISSING_IMPACT_CATEGORY => "Catégorie d'impact manquante",
        ImpactEligibilityService::REASON_CODE_DESCRIPTION_TOO_SHORT => 'Description trop courte',
        ImpactEligibilityService::REASON_CODE_FORBIDDEN_ACTIVITY => 'Activité interdite détectée',
        ImpactEligibilityService::REASON_CODE_LOW_IMPACT_SCORE => "Score d'impact insuffisant",
        ImpactEligibilityService::REASON_CODE_EVIDENCE_PROVIDER_UNAVAILABLE => 'Vérification externe indisponible',
    ];

    /**
     * @var array<string, string>
     */
    private const LEDGER_REFERENCE_LABELS = [
        'POINTS_CLAIM_APPROVAL' => 'Validation de demande de points',
        'APPLICATION_HIRED' => 'Candidature embauchée',
        'OFFER_PUBLICATION' => "Publication d'offre",
    ];

    /**
     * @var array<string, string>
     */
    private const LEDGER_REASON_LABELS = [
        'Points claim approved' => 'Demande de points validée',
        'Candidate points for hired application' => 'Points candidat attribués après embauche',
    ];

    /**
     * @var array<string, string>
     */
    private const CANDIDATE_LEVEL_LABELS = [
        'Bronze' => 'Bronze',
        'Silver' => 'Argent',
        'Gold' => 'Or',
        'Impact Leader' => 'Leader Impact',
    ];

    /**
     * @var array<string, string>
     */
    private const ACCOUNT_TYPE_LABELS = [
        User::ACCOUNT_TYPE_PERSON => 'Candidat',
        User::ACCOUNT_TYPE_COMPANY => 'Entreprise',
    ];

    public function pointsClaimReasonLabel(?string $reasonCode): string
    {
        $normalizedCode = $this->normalizeCode($reasonCode);
        if (null === $normalizedCode) {
            return '-';
        }

        return self::POINTS_CLAIM_REASON_LABELS[$normalizedCode] ?? $normalizedCode;
    }

    public function pointsClaimReviewActionLabel(?string $action): string
    {
        $normalizedAction = $this->normalizeCode($action);
        if (null === $normalizedAction) {
            return '-';
        }

        return self::REVIEW_ACTION_LABELS[$normalizedAction] ?? $normalizedAction;
    }

    public function policyDecisionStatusLabel(?string $status): string
    {
        $normalizedStatus = $this->normalizeCode($status);
        if (null === $normalizedStatus) {
            return '-';
        }

        return self::POLICY_STATUS_LABELS[$normalizedStatus] ?? $normalizedStatus;
    }

    public function policyReasonLabel(?string $reasonCode): string
    {
        return $this->pointsClaimReasonLabel($reasonCode);
    }

    public function pointsClaimStatusLabel(?string $status): string
    {
        $normalizedStatus = $this->normalizeCode($status);
        if (null === $normalizedStatus) {
            return '-';
        }

        return self::POINTS_CLAIM_STATUS_LABELS[$normalizedStatus] ?? $normalizedStatus;
    }

    public function pointsClaimTypeLabel(?string $type): string
    {
        $normalizedType = $this->normalizeCode($type);
        if (null === $normalizedType) {
            return '-';
        }

        return self::POINTS_CLAIM_TYPE_LABELS[$normalizedType] ?? $normalizedType;
    }

    public function offerStatusLabel(?string $status): string
    {
        $normalizedStatus = $this->normalizeCode($status);
        if (null === $normalizedStatus) {
            return '-';
        }

        return self::OFFER_STATUS_LABELS[$normalizedStatus] ?? $normalizedStatus;
    }

    public function offerModerationStatusLabel(?string $status): string
    {
        $normalizedStatus = $this->normalizeCode($status);
        if (null === $normalizedStatus) {
            return '-';
        }

        return self::OFFER_MODERATION_STATUS_LABELS[$normalizedStatus] ?? $normalizedStatus;
    }

    public function offerModerationReasonLabel(?string $reasonCode): string
    {
        $normalizedCode = $this->normalizeCode($reasonCode);
        if (null === $normalizedCode) {
            return '-';
        }

        return self::OFFER_MODERATION_REASON_LABELS[$normalizedCode] ?? $normalizedCode;
    }

    public function ledgerReferenceLabel(?string $referenceType): string
    {
        $normalizedReferenceType = $this->normalizeCode($referenceType);
        if (null === $normalizedReferenceType) {
            return '-';
        }

        return self::LEDGER_REFERENCE_LABELS[$normalizedReferenceType] ?? $normalizedReferenceType;
    }

    public function ledgerReasonLabel(?string $reason): string
    {
        if (!is_string($reason)) {
            return '-';
        }

        $normalizedReason = trim($reason);
        if ('' === $normalizedReason) {
            return '-';
        }

        return self::LEDGER_REASON_LABELS[$normalizedReason] ?? $normalizedReason;
    }

    public function candidateLevelLabel(?string $level): string
    {
        if (!is_string($level)) {
            return '-';
        }

        $normalizedLevel = trim($level);
        if ('' === $normalizedLevel) {
            return '-';
        }

        return self::CANDIDATE_LEVEL_LABELS[$normalizedLevel] ?? $normalizedLevel;
    }

    public function accountTypeLabel(?string $accountType): string
    {
        $normalizedAccountType = $this->normalizeCode($accountType);
        if (null === $normalizedAccountType) {
            return '-';
        }

        return self::ACCOUNT_TYPE_LABELS[$normalizedAccountType] ?? $normalizedAccountType;
    }

    private function normalizeCode(?string $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalizedValue = strtoupper(trim($value));

        return '' === $normalizedValue ? null : $normalizedValue;
    }
}
