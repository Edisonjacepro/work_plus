<?php

namespace App\Tests\Service;

use App\Entity\Offer;
use App\Entity\PointsClaim;
use App\Entity\PointsClaimReviewEvent;
use App\Service\ImpactEligibilityService;
use App\Service\PointsReasonLabelService;
use PHPUnit\Framework\TestCase;

class PointsReasonLabelServiceTest extends TestCase
{
    public function testPointsClaimReasonLabelReturnsFrenchLabel(): void
    {
        $service = new PointsReasonLabelService();

        self::assertSame(
            'Pause de sécurité activée',
            $service->pointsClaimReasonLabel(PointsClaim::REASON_CODE_COOLDOWN_ACTIVE),
        );
    }

    public function testOfferLabelsReturnFrenchValues(): void
    {
        $service = new PointsReasonLabelService();

        self::assertSame('Brouillon', $service->offerStatusLabel(Offer::STATUS_DRAFT));
        self::assertSame('Soumise', $service->offerModerationStatusLabel(Offer::MODERATION_STATUS_SUBMITTED));
        self::assertSame(
            "Catégorie d'impact manquante",
            $service->offerModerationReasonLabel(ImpactEligibilityService::REASON_CODE_MISSING_IMPACT_CATEGORY),
        );
    }

    public function testReviewActionLabelReturnsFrenchValue(): void
    {
        $service = new PointsReasonLabelService();

        self::assertSame(
            'Validée automatiquement',
            $service->pointsClaimReviewActionLabel(PointsClaimReviewEvent::ACTION_AUTO_APPROVED),
        );
    }

    public function testLedgerReferenceAndReasonLabelsAreLocalized(): void
    {
        $service = new PointsReasonLabelService();

        self::assertSame(
            'Validation de demande de points',
            $service->ledgerReferenceLabel('POINTS_CLAIM_APPROVAL'),
        );
        self::assertSame(
            'Demande de points validée',
            $service->ledgerReasonLabel('Points claim approved'),
        );
    }
}
