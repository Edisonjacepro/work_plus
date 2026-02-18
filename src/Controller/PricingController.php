<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Service\Billing\RecruiterPlanCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PricingController extends AbstractController
{
    public function __construct(
        private readonly RecruiterPlanCatalog $recruiterPlanCatalog,
    ) {
    }

    #[Route('/pricing', name: 'pricing_index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        $isRecruiterWithCompany = false;
        $activePlanCode = Company::RECRUITER_PLAN_STARTER;
        $activePlanExpiresAt = null;

        if ($user instanceof User && $user->isCompany() && $user->getCompany() instanceof Company) {
            $isRecruiterWithCompany = true;
            $activePlanCode = $user->getCompany()->getRecruiterPlanCode();
            $activePlanExpiresAt = $user->getCompany()->getRecruiterPlanExpiresAt();
        }

        $plans = [];
        foreach ($this->recruiterPlanCatalog->all() as $plan) {
            $plan['isPaid'] = $plan['priceCents'] > 0;
            $plan['isCurrent'] = $plan['code'] === $activePlanCode;
            $plans[] = $plan;
        }

        return $this->render('pricing/index.html.twig', [
            'plans' => $plans,
            'isRecruiterWithCompany' => $isRecruiterWithCompany,
            'activePlanCode' => $activePlanCode,
            'activePlanExpiresAt' => $activePlanExpiresAt,
        ]);
    }
}
