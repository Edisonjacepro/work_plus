<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PricingController extends AbstractController
{
    #[Route('/pricing', name: 'pricing_index', methods: ['GET'])]
    public function index(): Response
    {
        $plans = [
            [
                'name' => 'Starter',
                'price' => '0 EUR / mois',
                'subtitle' => 'Pour debuter avec une premiere equipe recrutement.',
                'ctaLabel' => 'Commencer gratuitement',
                'ctaRoute' => 'app_register_company',
                'highlight' => false,
                'features' => [
                    ['label' => 'Fiche entreprise + edition', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Creation et publication d offres impact', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Score d impact auto avant publication', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Reception candidatures + messagerie', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Points entreprise avec detail ledger', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Limite 1 recruteur par entreprise', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Limite 3 offres actives', 'available' => false, 'type' => 'Nouveau'],
                ],
            ],
            [
                'name' => 'Growth',
                'price' => '39 EUR / mois',
                'subtitle' => 'Pour structurer le recrutement impact en equipe.',
                'ctaLabel' => 'Passer a Growth',
                'ctaRoute' => 'app_register_company',
                'highlight' => true,
                'features' => [
                    ['label' => 'Tout le plan Starter', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Jusqu a 5 recruteurs', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Jusqu a 20 offres actives', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Export CSV des candidatures', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Analytics mensuels (offres, candidatures, points)', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Badge entreprise verifiee via preuves API', 'available' => false, 'type' => 'Nouveau'],
                ],
            ],
            [
                'name' => 'Scale',
                'price' => '99 EUR / mois',
                'subtitle' => 'Pour les organisations avec volume et integration SI.',
                'ctaLabel' => 'Passer a Scale',
                'ctaRoute' => 'app_register_company',
                'highlight' => false,
                'features' => [
                    ['label' => 'Tout le plan Growth', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Recruteurs illimites', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Offres actives illimitees', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Webhooks/API pour ATS externe', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Regles de scoring personnalisables', 'available' => false, 'type' => 'Nouveau'],
                    ['label' => 'Support prioritaire + onboarding', 'available' => false, 'type' => 'Nouveau'],
                ],
            ],
        ];

        return $this->render('pricing/index.html.twig', [
            'plans' => $plans,
        ]);
    }
}
