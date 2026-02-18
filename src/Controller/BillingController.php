<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Service\Billing\BillingGatewayRegistry;
use App\Service\Billing\RecruiterSubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/billing')]
class BillingController extends AbstractController
{
    #[Route('/checkout/{planCode}', name: 'billing_checkout', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function checkout(
        string $planCode,
        Request $request,
        RecruiterSubscriptionService $recruiterSubscriptionService,
        EntityManagerInterface $entityManager,
    ): Response {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isCompany() || !$user->getCompany() instanceof Company) {
            $this->addFlash('error', 'Acces refuse : paiement reserve aux recruteurs avec entreprise.');
            return $this->redirectToRoute('pricing_index');
        }

        $csrfTokenId = 'billing_checkout_' . strtoupper($planCode);
        if (!$this->isCsrfTokenValid($csrfTokenId, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('pricing_index');
        }

        try {
            $result = $recruiterSubscriptionService->startCheckout($user->getCompany(), $user, strtoupper($planCode));
            $entityManager->flush();

            if (null !== $result->redirectUrl && '' !== trim($result->redirectUrl)) {
                return $this->redirect($result->redirectUrl);
            }

            if ($result->alreadyProcessed) {
                $this->addFlash('info', 'Paiement deja initialise pour cette periode. Aucun doublon cree.');
            } elseif ($result->immediateSuccess) {
                $this->addFlash('success', sprintf('Formule %s activee. Paiement confirme.', $result->payment->getPlanCode()));
            } else {
                $this->addFlash('info', 'Paiement initialise. En attente de confirmation du fournisseur.');
            }
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash('error', $exception->getMessage());
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible de lancer le paiement pour le moment.');
        }

        return $this->redirectToRoute('pricing_index');
    }

    #[Route('/webhook/{provider}', name: 'billing_webhook', methods: ['POST'])]
    public function webhook(
        string $provider,
        Request $request,
        BillingGatewayRegistry $billingGatewayRegistry,
        RecruiterSubscriptionService $recruiterSubscriptionService,
        EntityManagerInterface $entityManager,
    ): Response {
        $payload = (string) $request->getContent();

        try {
            $gateway = $billingGatewayRegistry->resolve(strtolower($provider));
            $event = $gateway->parseWebhookEvent($payload, $request->headers->get('Stripe-Signature'));

            if (null === $event) {
                return new Response(null, Response::HTTP_ACCEPTED);
            }

            $recruiterSubscriptionService->handleWebhookEvent($event);
            $entityManager->flush();

            return new Response(null, Response::HTTP_NO_CONTENT);
        } catch (\InvalidArgumentException) {
            return new JsonResponse(['error' => 'Invalid webhook payload.'], Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            return new JsonResponse(['error' => 'Webhook processing failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}

