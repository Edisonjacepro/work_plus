<?php

namespace App\Service;

use App\Entity\Offer;

interface ImpactEvidenceProviderInterface
{
    /**
     * @return array<string, mixed>
     */
    public function collectForOffer(Offer $offer): array;
}
