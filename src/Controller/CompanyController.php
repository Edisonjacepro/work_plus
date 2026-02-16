<?php

namespace App\Controller;

use App\Entity\Company;
use App\Repository\CompanyRepository;
use App\Service\PointsLedgerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/companies')]
class CompanyController extends AbstractController
{
    #[Route('', name: 'company_index', methods: ['GET'])]
    public function index(CompanyRepository $companyRepository): Response
    {
        return $this->render('company/index.html.twig', [
            'companies' => $companyRepository->findAll(),
        ]);
    }

    #[Route('/{id}', name: 'company_show', methods: ['GET'])]
    public function show(Company $company, PointsLedgerService $pointsLedgerService): Response
    {
        return $this->render('company/show.html.twig', [
            'company' => $company,
            'impactPointsBalance' => $pointsLedgerService->getCompanyBalance($company),
        ]);
    }
}
