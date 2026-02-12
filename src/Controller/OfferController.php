<?php

namespace App\Controller;

use App\Entity\Offer;
use App\Form\OfferType;
use App\Repository\OfferRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/offers')]
class OfferController extends AbstractController
{
    #[Route('', name: 'offer_index', methods: ['GET'])]
    public function index(OfferRepository $offerRepository): Response
    {
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User && !$this->isGranted('ROLE_ADMIN')) {
            return $this->render('offer/index.html.twig', [
                'offers' => $offerRepository->findByAuthor($user->getId() ?? 0),
            ]);
        }

        return $this->render('offer/index.html.twig', [
            'offers' => $this->isGranted('ROLE_ADMIN')
                ? $offerRepository->findAll()
                : $offerRepository->findVisible(),
        ]);
    }

    #[Route('/new', name: 'offer_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (
            !$user instanceof \App\Entity\User
            || $user->getAccountType() !== \App\Entity\User::ACCOUNT_TYPE_COMPANY
            || !$user->getCompany()
        ) {
            $this->addFlash('error', 'Vous devez être connecté avec un compte entreprise pour créer une offre.');
            return $this->redirectToRoute('home');
        }

        $offer = new Offer();
        $offer->setAuthor($user);
        $offer->setCompany($user->getCompany());
        $form = $this->createForm(OfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($offer);
            $entityManager->flush();

            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        return $this->render('offer/new.html.twig', [
            'offer' => $offer,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'offer_show', methods: ['GET'])]
    public function show(Offer $offer): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\User || $offer->getAuthor()?->getId() !== $user->getId()) {
                throw $this->createAccessDeniedException();
            }
        }

        return $this->render('offer/show.html.twig', [
            'offer' => $offer,
        ]);
    }

    #[Route('/{id}/edit', name: 'offer_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\User || $offer->getAuthor()?->getId() !== $user->getId()) {
                throw $this->createAccessDeniedException();
            }
        }

        $form = $this->createForm(OfferType::class, $offer);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        return $this->render('offer/edit.html.twig', [
            'offer' => $offer,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'offer_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function delete(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted('ROLE_ADMIN')) {
            $user = $this->getUser();
            if (!$user instanceof \App\Entity\User || $offer->getAuthor()?->getId() !== $user->getId()) {
                throw $this->createAccessDeniedException();
            }
        }

        if ($this->isCsrfTokenValid('delete_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            $entityManager->remove($offer);
            $entityManager->flush();
        }

        return $this->redirectToRoute('offer_index');
    }

    #[Route('/{id}/publish', name: 'offer_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publish(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('publish_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                $user = $this->getUser();
                if (!$user instanceof \App\Entity\User || $offer->getAuthor()?->getId() !== $user->getId()) {
                    $this->addFlash('error', 'Seul l’auteur peut publier cette offre.');
                    return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
                }
            }

            $offer->setStatus(Offer::STATUS_PUBLISHED);
            $entityManager->flush();
        }

        return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
    }

    #[Route('/{id}/unpublish', name: 'offer_unpublish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unpublish(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('unpublish_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                $user = $this->getUser();
                if (!$user instanceof \App\Entity\User || $offer->getAuthor()?->getId() !== $user->getId()) {
                    $this->addFlash('error', 'Seul l’auteur peut modifier le statut de cette offre.');
                    return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
                }
            }

            $offer->setStatus(Offer::STATUS_DRAFT);
            $entityManager->flush();
        }

        return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
    }

    #[Route('/{id}/visibility', name: 'offer_toggle_visibility', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggleVisibility(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('toggle_visibility_' . $offer->getId(), (string) $request->request->get('_token'))) {
            $offer->setIsVisible(!$offer->isVisible());
            $entityManager->flush();
        }

        return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
    }
}
