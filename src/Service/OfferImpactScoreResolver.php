<?php

namespace App\Service;

use App\Dto\ImpactScoreResult;
use App\Entity\ImpactScore;
use App\Entity\Offer;
use App\Repository\ImpactScoreRepository;

class OfferImpactScoreResolver
{
    public function __construct(
        private readonly ImpactScoreRepository $impactScoreRepository,
        private readonly ImpactScoringService $impactScoringService,
    ) {
    }

    /**
     * @return array{impactScore: ImpactScore|ImpactScoreResult|null, isPreview: bool}
     */
    public function resolve(Offer $offer): array
    {
        $offerId = $offer->getId();
        if (null !== $offerId) {
            $storedScore = $this->impactScoreRepository->findLatestForOffer($offerId);
            if (null !== $storedScore) {
                return [
                    'impactScore' => $storedScore,
                    'isPreview' => false,
                ];
            }
        }

        return [
            'impactScore' => $this->impactScoringService->score($offer),
            'isPreview' => true,
        ];
    }
}
