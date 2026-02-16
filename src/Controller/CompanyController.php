<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Form\CompanyType;
use App\Repository\CompanyRepository;
use App\Security\CompanyVoter;
use App\Service\PointsLedgerService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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

    #[Route('/new', name: 'company_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isCompany()) {
            $this->addFlash('error', 'Acces refuse : vous devez etre connecte en tant que recruteur.');
            return $this->redirectToRoute('company_index');
        }

        if ($user->getCompany() instanceof Company) {
            $this->addFlash('error', 'Vous etes deja rattache a une entreprise.');
            return $this->redirectToRoute('company_show', ['id' => $user->getCompany()?->getId()]);
        }

        $company = new Company();
        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setCompany($company);
            $entityManager->persist($company);
            $entityManager->flush();

            $this->addFlash('success', 'Entreprise creee avec succes.');
            return $this->redirectToRoute('company_show', ['id' => $company->getId()]);
        }

        return $this->render('company/form.html.twig', [
            'company' => $company,
            'form' => $form,
            'pageTitle' => 'Creer une entreprise',
            'submitLabel' => 'Creer',
        ]);
    }

    #[Route('/{id}', name: 'company_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(Company $company, PointsLedgerService $pointsLedgerService): Response
    {
        $summary = $pointsLedgerService->getCompanySummary($company);

        return $this->render('company/show.html.twig', [
            'company' => $company,
            'impactPointsBalance' => $summary['balance'],
            'companyPointsHistory' => $summary['history'],
        ]);
    }

    #[Route('/{id}/edit', name: 'company_edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Company $company, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted(CompanyVoter::EDIT, $company)) {
            $this->addFlash('error', 'Acces refuse : vous ne pouvez pas modifier cette entreprise.');
            return $this->redirectToRoute('company_show', ['id' => $company->getId()]);
        }

        $form = $this->createForm(CompanyType::class, $company);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'Entreprise mise a jour avec succes.');
            return $this->redirectToRoute('company_show', ['id' => $company->getId()]);
        }

        return $this->render('company/form.html.twig', [
            'company' => $company,
            'form' => $form,
            'pageTitle' => 'Modifier entreprise',
            'submitLabel' => 'Enregistrer',
        ]);
    }

    #[Route('/{id}', name: 'company_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, Company $company, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted(CompanyVoter::DELETE, $company)) {
            $this->addFlash('error', 'Acces refuse : vous ne pouvez pas supprimer cette entreprise.');
            return $this->redirectToRoute('company_show', ['id' => $company->getId()]);
        }

        if (!$this->isCsrfTokenValid('delete_company_' . $company->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('company_show', ['id' => $company->getId()]);
        }

        $hasOffers = $company->getOffers()->count() > 0;
        $usersCount = $company->getUsers()->count();
        if ($hasOffers || $usersCount > 1) {
            $this->addFlash('error', 'Suppression impossible : entreprise liee a des offres ou a plusieurs utilisateurs.');
            return $this->redirectToRoute('company_show', ['id' => $company->getId()]);
        }

        foreach ($company->getUsers() as $linkedUser) {
            $linkedUser->setCompany(null);
        }

        $entityManager->remove($company);
        $entityManager->flush();

        $this->addFlash('success', 'Entreprise supprimee avec succes.');
        return $this->redirectToRoute('company_index');
    }
}
