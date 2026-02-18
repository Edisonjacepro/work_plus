<?php

namespace App\Service\Billing;

use App\Entity\Company;

class RecruiterPlanCatalog
{
    /**
     * @return list<array{
     *     code: string,
     *     name: string,
     *     price: string,
     *     priceCents: int,
     *     currencyCode: string,
     *     subtitle: string,
     *     highlight: bool,
     *     features: list<array{label: string, available: bool, type: string}>
     * }>
     */
    public function all(): array
    {
        return [
            [
                'code' => Company::RECRUITER_PLAN_STARTER,
                'name' => 'Starter',
                'price' => '0 EUR / mois',
                'priceCents' => 0,
                'currencyCode' => 'EUR',
                'subtitle' => 'Pour debuter avec une premiere equipe recrutement.',
                'highlight' => false,
                'features' => [
                    ['label' => 'Fiche entreprise + edition', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Creation et publication d offres impact', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Score d impact auto avant publication', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Reception candidatures + messagerie', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Points entreprise avec detail ledger', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Limite 1 recruteur par entreprise', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Limite 3 offres actives', 'available' => true, 'type' => 'Nouveau'],
                ],
            ],
            [
                'code' => Company::RECRUITER_PLAN_GROWTH,
                'name' => 'Growth',
                'price' => '39 EUR / mois',
                'priceCents' => 3900,
                'currencyCode' => 'EUR',
                'subtitle' => 'Pour structurer le recrutement impact en equipe.',
                'highlight' => true,
                'features' => [
                    ['label' => 'Tout le plan Starter', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Jusqu a 5 recruteurs', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Jusqu a 20 offres actives', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Export CSV des candidatures', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Analytics mensuels (offres, candidatures, points)', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Badge entreprise verifiee via preuves API', 'available' => true, 'type' => 'Nouveau'],
                ],
            ],
            [
                'code' => Company::RECRUITER_PLAN_SCALE,
                'name' => 'Scale',
                'price' => '99 EUR / mois',
                'priceCents' => 9900,
                'currencyCode' => 'EUR',
                'subtitle' => 'Pour les organisations avec volume et integration SI.',
                'highlight' => false,
                'features' => [
                    ['label' => 'Tout le plan Growth', 'available' => true, 'type' => 'Existant'],
                    ['label' => 'Recruteurs illimites', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Offres actives illimitees', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Webhooks/API pour ATS externe', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Regles de scoring personnalisables', 'available' => true, 'type' => 'Nouveau'],
                    ['label' => 'Support prioritaire + onboarding', 'available' => true, 'type' => 'Nouveau'],
                ],
            ],
        ];
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     price: string,
     *     priceCents: int,
     *     currencyCode: string,
     *     subtitle: string,
     *     highlight: bool,
     *     features: list<array{label: string, available: bool, type: string}>
     * }|null
     */
    public function get(string $planCode): ?array
    {
        foreach ($this->all() as $plan) {
            if ($plan['code'] === strtoupper($planCode)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     code: string,
     *     name: string,
     *     price: string,
     *     priceCents: int,
     *     currencyCode: string,
     *     subtitle: string,
     *     highlight: bool,
     *     features: list<array{label: string, available: bool, type: string}>
     * }
     */
    public function getPaidPlanOrFail(string $planCode): array
    {
        $plan = $this->get($planCode);
        if (null === $plan) {
            throw new \InvalidArgumentException('Plan inconnu.');
        }

        if ($plan['priceCents'] <= 0) {
            throw new \InvalidArgumentException('Le plan demande est gratuit.');
        }

        return $plan;
    }
}

