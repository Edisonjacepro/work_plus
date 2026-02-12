<?php

namespace App\Service;

use App\Entity\ModerationReview;
use App\Entity\Offer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class ModerationService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function submit(Offer $offer, User $actor): void
    {
        if ($offer->getStatus() !== Offer::STATUS_DRAFT) {
            throw new \DomainException('Only draft offers can be submitted.');
        }

        $offer->setStatus(Offer::STATUS_SUBMITTED);
        $this->entityManager->flush();
    }

    public function approve(Offer $offer, User $reviewer, ?string $reason = null): ModerationReview
    {
        return $this->decide($offer, $reviewer, ModerationReview::DECISION_APPROVED, $reason);
    }

    public function reject(Offer $offer, User $reviewer, string $reason): ModerationReview
    {
        if (trim($reason) === '') {
            throw new \DomainException('A rejection reason is required.');
        }

        return $this->decide($offer, $reviewer, ModerationReview::DECISION_REJECTED, $reason);
    }

    private function decide(Offer $offer, User $reviewer, string $decision, ?string $reason): ModerationReview
    {
        if ($offer->getStatus() !== Offer::STATUS_SUBMITTED) {
            throw new \DomainException('Only submitted offers can be moderated.');
        }

        $offer->setStatus($decision === ModerationReview::DECISION_APPROVED
            ? Offer::STATUS_APPROVED
            : Offer::STATUS_REJECTED);

        $review = (new ModerationReview())
            ->setOffer($offer)
            ->setReviewer($reviewer)
            ->setDecision($decision)
            ->setReason($reason);

        $offer->addModerationReview($review);

        $this->entityManager->persist($review);
        $this->entityManager->flush();

        return $review;
    }
}
