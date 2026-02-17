<?php

namespace App\Controller;

use App\Entity\Application;
use App\Entity\Offer;
use App\Entity\User;
use App\Form\ApplicationType;
use App\Form\OfferType;
use App\Repository\OfferRepository;
use App\Repository\UserRepository;
use App\Security\OfferVoter;
use App\Service\CandidatePointsService;
use App\Service\ImpactScoreService;
use App\Service\OfferImpactScoreResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        return $this->render('offer/index.html.twig', [
            'offers' => $offerRepository->findPublicPublished(),
        ]);
    }

    #[Route('/recruiter', name: 'recruiter_offer_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function recruiterIndex(OfferRepository $offerRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isCompany()) {
            $this->addFlash('error', 'Acces refuse : vous devez etre un recruteur.');
            return $this->redirectToRoute('offer_index');
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->render('offer/recruiter_index.html.twig', [
                'offers' => $offerRepository->findAll(),
            ]);
        }

        return $this->render('offer/recruiter_index.html.twig', [
            'offers' => $offerRepository->findByAuthor($user->getId() ?? 0),
        ]);
    }

    #[Route('/new', name: 'offer_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isCompany() || !$user->getCompany()) {
            $this->addFlash('error', 'Veuillez vous connecter avec un compte entreprise pour creer une offre.');
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
    public function show(Offer $offer, OfferImpactScoreResolver $offerImpactScoreResolver): Response
    {
        if (!$this->isGranted(OfferVoter::VIEW, $offer)) {
            $this->addFlash('error', 'Acces refuse : offre non disponible.');
            return $this->redirectToRoute('offer_index');
        }

        $user = $this->getUser();
        $canApply = !$user instanceof User || $user->isPerson();
        $applicationForm = null;

        if ($canApply) {
            $application = new Application();
            $application->setOffer($offer);

            if ($user instanceof User) {
                $application->setCandidate($user);
                if (null !== $user->getEmail()) {
                    $application->setEmail($user->getEmail());
                }
                if (null !== $user->getFirstName()) {
                    $application->setFirstName($user->getFirstName());
                }
                if (null !== $user->getLastName()) {
                    $application->setLastName($user->getLastName());
                }
            }

            $applicationForm = $this->createForm(ApplicationType::class, $application, [
                'action' => $this->generateUrl('offer_apply', ['id' => $offer->getId()]),
                'method' => 'POST',
            ])->createView();
        }

        $resolvedScore = $offerImpactScoreResolver->resolve($offer);

        return $this->render('offer/show.html.twig', [
            'offer' => $offer,
            'applicationForm' => $applicationForm,
            'canApply' => $canApply,
            'impactScore' => $resolvedScore['impactScore'],
            'isImpactScorePreview' => $resolvedScore['isPreview'],
        ]);
    }

    #[Route('/{id}/apply', name: 'offer_apply', methods: ['POST'])]
    public function apply(
        Request $request,
        Offer $offer,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        CandidatePointsService $candidatePointsService,
        string $cvUploadDir,
    ): Response
    {
        if (!$this->isGranted(OfferVoter::VIEW, $offer)) {
            $this->addFlash('error', 'Acces refuse : offre non disponible.');
            return $this->redirectToRoute('offer_index');
        }

        $user = $this->getUser();
        if ($user instanceof User && !$user->isPerson()) {
            $this->addFlash('error', 'Seul un candidat peut postuler a cette offre.');
            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        $application = new Application();
        $application->setOffer($offer);

        if ($user instanceof User && $user->isPerson()) {
            $application->setCandidate($user);
            if (null !== $user->getEmail()) {
                $application->setEmail($user->getEmail());
            }
            if (null !== $user->getFirstName()) {
                $application->setFirstName($user->getFirstName());
            }
            if (null !== $user->getLastName()) {
                $application->setLastName($user->getLastName());
            }
        }

        $form = $this->createForm(ApplicationType::class, $application);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$user instanceof User && $userRepository->existsByEmailInsensitive((string) $application->getEmail())) {
                $form->get('email')->addError(new FormError('Cet email correspond deja a un compte existant. Veuillez vous connecter pour postuler.'));
                $this->addFlash('error', 'Cet email correspond deja a un compte existant. Veuillez vous connecter pour postuler.');

                return $this->render('offer/show.html.twig', [
                    'offer' => $offer,
                    'applicationForm' => $form->createView(),
                    'canApply' => true,
                ]);
            }

            /** @var UploadedFile|null $cvFile */
            $cvFile = $form->get('cvFile')->getData();
            if ($cvFile instanceof UploadedFile) {
                $safeFileName = bin2hex(random_bytes(12));
                $extension = $cvFile->guessExtension() ?: $cvFile->getClientOriginalExtension();
                $fileName = $safeFileName . ($extension ? '.' . strtolower($extension) : '');

                try {
                    $cvFile->move($cvUploadDir, $fileName);
                    $application->setCvFilePath('uploads/cv/' . $fileName);
                } catch (FileException) {
                    $this->addFlash('error', 'Impossible de televerser votre CV. Veuillez reessayer.');
                    return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
                }
            }

            $entityManager->persist($application);
            $entityManager->flush();

            $candidatePointsEntry = $candidatePointsService->awardApplicationSubmissionPoints($application);
            if (null !== $candidatePointsEntry) {
                $entityManager->flush();
                $this->addFlash('success', sprintf('Votre candidature a ete envoyee. +%d points candidat.', $candidatePointsEntry->getPoints()));
            } else {
                $this->addFlash('success', 'Votre candidature a ete envoyee.');
            }

            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        $this->addFlash('error', 'Le formulaire de candidature est invalide.');

        return $this->render('offer/show.html.twig', [
            'offer' => $offer,
            'applicationForm' => $form->createView(),
            'canApply' => true,
        ]);
    }

    #[Route('/{id}/edit', name: 'offer_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted(OfferVoter::EDIT, $offer)) {
            $this->addFlash('error', 'Acces refuse : cette offre ne vous appartient pas.');
            return $this->redirectToRoute('offer_index');
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
        if (!$this->isGranted(OfferVoter::DELETE, $offer)) {
            $this->addFlash('error', 'Acces refuse : cette offre ne vous appartient pas.');
            return $this->redirectToRoute('offer_index');
        }

        if ($this->isCsrfTokenValid('delete_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            $entityManager->remove($offer);
            $entityManager->flush();
        }

        return $this->redirectToRoute('offer_index');
    }

    #[Route('/{id}/publish', name: 'offer_publish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function publish(
        Request $request,
        Offer $offer,
        EntityManagerInterface $entityManager,
        ImpactScoreService $impactScoreService
    ): Response
    {
        if ($this->isCsrfTokenValid('publish_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            if (!$this->isGranted(OfferVoter::PUBLISH, $offer)) {
                $this->addFlash('error', 'Seul l\'auteur peut publier cette offre.');
                return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
            }

            $offer->setStatus(Offer::STATUS_PUBLISHED);
            $offer->setPublishedAt(new \DateTimeImmutable());

            $impactScoreService->computeAndStore($offer);

            $entityManager->flush();
            $this->addFlash('success', 'Publication reussie.');
        }

        return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
    }

    #[Route('/{id}/unpublish', name: 'offer_unpublish', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function unpublish(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('unpublish_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            if (!$this->isGranted(OfferVoter::UNPUBLISH, $offer)) {
                $this->addFlash('error', 'Seul l\'auteur peut modifier le statut de cette offre.');
                return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
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
