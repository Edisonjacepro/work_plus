<?php

namespace App\Service;

use App\Dto\EligibilityResult;
use App\Entity\ModerationReview;
use App\Entity\Offer;
use Doctrine\ORM\EntityManagerInterface;

class ModerationService
{
    public function __construct(
        private readonly ImpactEligibilityService $impactEligibilityService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function moderateForPublication(Offer $offer): EligibilityResult
    {
        $offer
            ->setModerationStatus(Offer::MODERATION_STATUS_SUBMITTED)
            ->setModerationReasonCode(null)
            ->setModerationReason(null)
            ->setModerationScore(0)
            ->setModerationRuleVersion(Offer::MODERATION_RULE_VERSION_V1);

        $this->entityManager->persist($offer);
        $this->createReview(
            offer: $offer,
            action: ModerationReview::ACTION_SUBMITTED,
            reasonCode: null,
            reasonText: null,
            moderationScore: 0,
            metadata: [
                'impactCategories' => $offer->getImpactCategories(),
            ],
        );

        $result = $this->impactEligibilityService->evaluate($offer);
        $moderatedAt = new \DateTimeImmutable();

        if ($result->eligible) {
            $offer
                ->setModerationStatus(Offer::MODERATION_STATUS_APPROVED)
                ->setModerationReasonCode($result->reasonCode)
                ->setModerationReason($result->reasonText)
                ->setModerationScore($result->score)
                ->setModerationRuleVersion($result->ruleVersion)
                ->setModeratedAt($moderatedAt)
                ->setStatus(Offer::STATUS_PUBLISHED)
                ->setPublishedAt($moderatedAt)
                ->setIsVisible(true);

            $this->createReview(
                offer: $offer,
                action: ModerationReview::ACTION_AUTO_APPROVED,
                reasonCode: $result->reasonCode,
                reasonText: $result->reasonText,
                moderationScore: $result->score,
                metadata: $result->metadata,
                ruleVersion: $result->ruleVersion,
            );
        } else {
            $offer
                ->setModerationStatus(Offer::MODERATION_STATUS_REJECTED)
                ->setModerationReasonCode($result->reasonCode)
                ->setModerationReason($result->reasonText)
                ->setModerationScore($result->score)
                ->setModerationRuleVersion($result->ruleVersion)
                ->setModeratedAt($moderatedAt)
                ->setStatus(Offer::STATUS_DRAFT)
                ->setPublishedAt(null)
                ->setIsVisible(false);

            $this->createReview(
                offer: $offer,
                action: ModerationReview::ACTION_AUTO_REJECTED,
                reasonCode: $result->reasonCode,
                reasonText: $result->reasonText,
                moderationScore: $result->score,
                metadata: $result->metadata,
                ruleVersion: $result->ruleVersion,
            );
        }

        $this->entityManager->persist($offer);

        return $result;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function createReview(
        Offer $offer,
        string $action,
        ?string $reasonCode,
        ?string $reasonText,
        int $moderationScore,
        ?array $metadata = null,
        string $ruleVersion = Offer::MODERATION_RULE_VERSION_V1,
    ): void {
        $review = (new ModerationReview())
            ->setOffer($offer)
            ->setAction($action)
            ->setReasonCode($reasonCode)
            ->setReasonText($reasonText)
            ->setModerationScore($moderationScore)
            ->setRuleVersion($ruleVersion)
            ->setMetadata($metadata);

        $this->entityManager->persist($review);
    }
}
