<?php

namespace App\Controller;

use App\Entity\ModerationReview;
use App\Entity\Offer;
use App\Form\ModerationDecisionType;
use App\Service\ModerationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/offers')]
class ModerationController extends AbstractController
{
    #[Route('/{id}/submit', name: 'offer_submit', methods: ['POST'])]
    public function submit(Request $request, Offer $offer, ModerationService $moderationService, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('submit_offer_' . $offer->getId(), (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('offer_show', ['id' => $offer->getId()]);
        }

        $actorId = (int) $request->request->get('actor_id');
        $actor = $entityManager->getRepository(\App\Entity\User::class)->find($actorId);
        if (!$actor) {
            $this->addFlash('error', 'Sélectionnez un auteur valide.');
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
    public function moderate(Request $request, Offer $offer, ModerationService $moderationService): Response
    {
        $review = new ModerationReview();
        $form = $this->createForm(ModerationDecisionType::class, $review);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                if ($review->getDecision() === ModerationReview::DECISION_APPROVED) {
                    $moderationService->approve($offer, $review->getReviewer(), $review->getReason());
                } else {
                    $moderationService->reject($offer, $review->getReviewer(), (string) $review->getReason());
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
