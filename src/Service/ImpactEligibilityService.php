<?php

namespace App\Service;

use App\Dto\EligibilityResult;
use App\Entity\Offer;

class ImpactEligibilityService
{
    public const REASON_CODE_ELIGIBLE = 'ELIGIBLE';
    public const REASON_CODE_MISSING_IMPACT_CATEGORY = 'MISSING_IMPACT_CATEGORY';
    public const REASON_CODE_DESCRIPTION_TOO_SHORT = 'DESCRIPTION_TOO_SHORT';
    public const REASON_CODE_FORBIDDEN_ACTIVITY = 'FORBIDDEN_ACTIVITY';
    public const REASON_CODE_LOW_IMPACT_SCORE = 'LOW_IMPACT_SCORE';
    public const REASON_CODE_EVIDENCE_PROVIDER_UNAVAILABLE = 'EVIDENCE_PROVIDER_UNAVAILABLE';

    private const MIN_DESCRIPTION_LENGTH = 120;
    private const MIN_APPROVAL_SCORE = 40;

    /**
     * @var list<string>
     */
    private const FORBIDDEN_KEYWORDS = [
        'violence',
        'arme',
        'armes',
        'haine',
        'crime',
        'criminel',
        'trafic',
        'exploitation',
    ];

    public function __construct(private readonly ImpactScoringService $impactScoringService)
    {
    }

    public function evaluate(Offer $offer): EligibilityResult
    {
        if ([] === $offer->getImpactCategories()) {
            return new EligibilityResult(
                eligible: false,
                reasonCode: self::REASON_CODE_MISSING_IMPACT_CATEGORY,
                reasonText: 'Aucune categorie d impact selectionnee.',
                score: 0,
                ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
            );
        }

        $description = trim((string) $offer->getDescription());
        if (mb_strlen($description, 'UTF-8') < self::MIN_DESCRIPTION_LENGTH) {
            return new EligibilityResult(
                eligible: false,
                reasonCode: self::REASON_CODE_DESCRIPTION_TOO_SHORT,
                reasonText: 'Description insuffisante pour justifier l impact.',
                score: 0,
                ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
                metadata: ['descriptionLength' => mb_strlen($description, 'UTF-8')],
            );
        }

        $haystack = mb_strtolower(trim(((string) $offer->getTitle()) . ' ' . $description), 'UTF-8');
        foreach (self::FORBIDDEN_KEYWORDS as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return new EligibilityResult(
                    eligible: false,
                    reasonCode: self::REASON_CODE_FORBIDDEN_ACTIVITY,
                    reasonText: 'Contenu non eligible detecte par regle automatique.',
                    score: 0,
                    ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
                    metadata: ['matchedKeyword' => $keyword],
                );
            }
        }

        try {
            $impactScoreResult = $this->impactScoringService->score($offer);
        } catch (\Throwable) {
            return new EligibilityResult(
                eligible: false,
                reasonCode: self::REASON_CODE_EVIDENCE_PROVIDER_UNAVAILABLE,
                reasonText: 'Verification externe indisponible.',
                score: 0,
                ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
            );
        }

        $categoriesCount = count($offer->getImpactCategories());
        $coverageBonus = min(15, $categoriesCount * 5);
        $score = (int) round(
            ($impactScoreResult->totalScore * 0.7)
            + ($impactScoreResult->confidence * 20)
            + $coverageBonus
        );
        $score = max(0, min(100, $score));

        if ($score < self::MIN_APPROVAL_SCORE) {
            return new EligibilityResult(
                eligible: false,
                reasonCode: self::REASON_CODE_LOW_IMPACT_SCORE,
                reasonText: 'Score d impact insuffisant pour publication.',
                score: $score,
                ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
                metadata: [
                    'totalImpactScore' => $impactScoreResult->totalScore,
                    'confidence' => $impactScoreResult->confidence,
                    'categoriesCount' => $categoriesCount,
                ],
            );
        }

        return new EligibilityResult(
            eligible: true,
            reasonCode: self::REASON_CODE_ELIGIBLE,
            reasonText: 'Annonce eligible selon la regle automatique.',
            score: $score,
            ruleVersion: Offer::MODERATION_RULE_VERSION_V1,
            metadata: [
                'totalImpactScore' => $impactScoreResult->totalScore,
                'confidence' => $impactScoreResult->confidence,
                'categoriesCount' => $categoriesCount,
            ],
        );
    }
}
