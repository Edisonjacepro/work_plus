<?php

namespace App\Controller;

use App\Entity\ModerationReview;
use App\Entity\Offer;
use App\Form\ModerationDecisionType;
use App\Service\ModerationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/offers')]
class ModerationController extends AbstractController
{
    #[Route('/{id}/submit', name: 'offer_submit', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function submit(Request $request, Offer $offer, ModerationService $moderationService): Response
    {
        if (!$this->isCsrfTokenValid('submit_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        $actor = $this->getUser();
        if (!$actor instanceof \App\Entity\User) {
            $this->addFlash('error', 'Vous devez être connecté pour soumettre une offre.');
            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        try {
            $moderationService->submit($offer, $actor);
            $this->addFlash('success', 'Offre soumise à la modération.');
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
    }

    #[Route('/{id}/moderate', name: 'offer_moderate', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function moderate(Request $request, Offer $offer, ModerationService $moderationService): Response
    {
        $reviewer = $this->getUser();
        if (!$reviewer instanceof \App\Entity\User) {
            $this->addFlash('error', 'Vous devez être connecté pour modérer.');
            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        $review = new ModerationReview();
        $form = $this->createForm(ModerationDecisionType::class, $review);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($review->getDecision() === ModerationReview::DECISION_APPROVED) {
                    $moderationService->approve($offer, $reviewer, $review->getReason());
                } else {
                    $moderationService->reject($offer, $reviewer, (string) $review->getReason());
                }

                $this->addFlash('success', 'Décision enregistrée.');
                return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());
            }
        }

        return $this->render('offer/moderate.html.twig', [
            'offer' => $offer,
            'form' => $form,
        ]);
    }
}
