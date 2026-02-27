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
use App\Service\ImpactScoreService;
use App\Service\ModerationService;
use App\Service\OfferImpactScoreResolver;
use App\Service\PointsReasonLabelService;
use App\Service\RequestRateLimiterService;
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
    public function index(Request $request, OfferRepository $offerRepository): Response
    {
        $perPage = 12;
        $currentPage = max(1, $request->query->getInt('page', 1));
        $result = $offerRepository->findPublicPublishedPaginated($currentPage, $perPage);
        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));

        return $this->render('offer/index.html.twig', [
            'offers' => $result['items'],
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $total,
        ]);
    }

    #[Route('/recruiter', name: 'recruiter_offer_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function recruiterIndex(
        Request $request,
        OfferRepository $offerRepository,
        PointsReasonLabelService $pointsReasonLabelService,
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isCompany()) {
            $this->addFlash('error', 'Accès refusé : vous devez être un recruteur.');
            return $this->redirectToRoute('offer_index');
        }

        $perPage = 20;
        $currentPage = max(1, $request->query->getInt('page', 1));

        if ($this->isGranted('ROLE_ADMIN')) {
            $result = $offerRepository->findAllPaginated($currentPage, $perPage);
            $total = $result['total'];
            $totalPages = max(1, (int) ceil($total / $perPage));

            return $this->render('offer/recruiter_index.html.twig', [
                'offers' => $result['items'],
                'currentPage' => $currentPage,
                'totalPages' => $totalPages,
                'totalItems' => $total,
                'pointsReasonLabelService' => $pointsReasonLabelService,
            ]);
        }

        $result = $offerRepository->findByAuthorPaginated((int) ($user->getId() ?? 0), $currentPage, $perPage);
        $total = $result['total'];
        $totalPages = max(1, (int) ceil($total / $perPage));

        return $this->render('offer/recruiter_index.html.twig', [
            'offers' => $result['items'],
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $total,
            'pointsReasonLabelService' => $pointsReasonLabelService,
        ]);
    }

    #[Route('/new', name: 'offer_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isCompany() || !$user->getCompany()) {
            $this->addFlash('error', 'Veuillez vous connecter avec un compte entreprise pour créer une offre.');
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
    public function show(
        Offer $offer,
        OfferImpactScoreResolver $offerImpactScoreResolver,
        PointsReasonLabelService $pointsReasonLabelService,
    ): Response
    {
        if (!$this->isGranted(OfferVoter::VIEW, $offer)) {
            $this->addFlash('error', 'Accès refusé : offre non disponible.');
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
            'pointsReasonLabelService' => $pointsReasonLabelService,
        ]);
    }

    #[Route('/{id}/apply', name: 'offer_apply', methods: ['POST'])]
    public function apply(
        Request $request,
        Offer $offer,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        string $cvUploadDir,
        PointsReasonLabelService $pointsReasonLabelService,
    ): Response
    {
        if (!$this->isGranted(OfferVoter::VIEW, $offer)) {
            $this->addFlash('error', 'Accès refusé : offre non disponible.');
            return $this->redirectToRoute('offer_index');
        }

        $user = $this->getUser();
        if ($user instanceof User && !$user->isPerson()) {
            $this->addFlash('error', "Seul un candidat peut postuler à cette offre.");
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
                $form->get('email')->addError(new FormError('Cet email correspond déjà à un compte existant. Veuillez vous connecter pour postuler.'));
                $this->addFlash('error', 'Cet email correspond déjà à un compte existant. Veuillez vous connecter pour postuler.');

                return $this->render('offer/show.html.twig', [
                    'offer' => $offer,
                    'applicationForm' => $form->createView(),
                    'canApply' => true,
                    'pointsReasonLabelService' => $pointsReasonLabelService,
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
                    $this->addFlash('error', 'Impossible de téléverser votre CV. Veuillez réessayer.');
                    return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
                }
            }

            $entityManager->persist($application);
            $entityManager->flush();

            $this->addFlash('success', 'Votre candidature a été envoyée.');

            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        $this->addFlash('error', 'Le formulaire de candidature est invalide.');

        return $this->render('offer/show.html.twig', [
            'offer' => $offer,
            'applicationForm' => $form->createView(),
            'canApply' => true,
            'pointsReasonLabelService' => $pointsReasonLabelService,
        ]);
    }

    #[Route('/{id}/edit', name: 'offer_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Offer $offer, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isGranted(OfferVoter::EDIT, $offer)) {
            $this->addFlash('error', 'Accès refusé : cette offre ne vous appartient pas.');
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
            $this->addFlash('error', 'Accès refusé : cette offre ne vous appartient pas.');
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
        ImpactScoreService $impactScoreService,
        ModerationService $moderationService,
        RequestRateLimiterService $requestRateLimiterService,
        PointsReasonLabelService $pointsReasonLabelService,
    ): Response
    {
        if ($this->isCsrfTokenValid('publish_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            if (!$this->isGranted(OfferVoter::PUBLISH, $offer)) {
                $this->addFlash('error', 'Seul l\'auteur peut publier cette offre.');
                return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
            }

            $user = $this->getUser();
            if (!$user instanceof User) {
                throw $this->createAccessDeniedException();
            }

            $requestRateLimiterService->consumeOfferPublish($user);

            $eligibility = $moderationService->moderateForPublication($offer);

            if ($eligibility->eligible) {
                $impactScoreService->computeAndStore($offer);
                $entityManager->flush();
                $this->addFlash('success', 'Publication validée automatiquement.');
            } else {
                $entityManager->flush();
                $reasonCode = $offer->getModerationReasonCode();
                $this->addFlash('error', sprintf(
                    'Publication refusée automatiquement : %s%s.',
                    $pointsReasonLabelService->offerModerationReasonLabel($reasonCode),
                    is_string($reasonCode) && '' !== trim($reasonCode) ? sprintf(' (%s)', $reasonCode) : '',
                ));
            }
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
