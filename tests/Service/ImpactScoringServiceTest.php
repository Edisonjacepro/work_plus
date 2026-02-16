<?php

namespace App\Tests\Service;

use App\Entity\Offer;
use App\Service\ImpactEvidenceProviderInterface;
use App\Service\ImpactScoringService;
use PHPUnit\Framework\TestCase;

class ImpactScoringServiceTest extends TestCase
{
    public function testScoresOfferFromConcreteEvidenceOnly(): void
    {
        $provider = new class() implements ImpactEvidenceProviderInterface {
            public function collectForOffer(Offer $offer): array
            {
                return [
                    'company' => [
                        'checked' => true,
                        'found' => true,
                        'active' => true,
                        'isEss' => true,
                        'isMissionCompany' => false,
                        'hasGesReport' => true,
                    ],
                    'location' => [
                        'checked' => true,
                        'validated' => true,
                    ],
                ];
            }
        };

        $service = new ImpactScoringService($provider);

        $offer = (new Offer())
            ->setTitle('Offre test')
            ->setDescription('Description courte sans mots cles impact.')
            ->setImpactCategories(['societe', 'biodiversite', 'ges']);

        $result = $service->score($offer);

        self::assertSame(0.8, $result->confidence);
        self::assertSame(75, $result->societyScore);
        self::assertSame(25, $result->biodiversityScore);
        self::assertSame(60, $result->ghgScore);
        self::assertSame(44, $result->totalScore);
    }

    public function testOfferWithoutEvidenceGetsNoAxisPoints(): void
    {
        $provider = new class() implements ImpactEvidenceProviderInterface {
            public function collectForOffer(Offer $offer): array
            {
                return [
                    'company' => [
                        'checked' => false,
                        'found' => false,
                        'active' => false,
                        'isEss' => false,
                        'isMissionCompany' => false,
                        'hasGesReport' => false,
                    ],
                    'location' => [
                        'checked' => false,
                        'validated' => false,
                    ],
                ];
            }
        };

        $service = new ImpactScoringService($provider);

        $offer = (new Offer())
            ->setTitle('Reduction carbone')
            ->setDescription('CO2')
            ->setImpactCategories(['ges']);

        $result = $service->score($offer);

        self::assertSame(0.35, $result->confidence);
        self::assertSame(0, $result->societyScore);
        self::assertSame(0, $result->biodiversityScore);
        self::assertSame(0, $result->ghgScore);
        self::assertSame(0, $result->totalScore);
    }
}
