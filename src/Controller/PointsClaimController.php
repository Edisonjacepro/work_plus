<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\Offer;
use App\Entity\PointsClaim;
use App\Entity\User;
use App\Form\PointsClaimType;
use App\Repository\OfferRepository;
use App\Repository\PointsClaimRepository;
use App\Repository\PointsClaimReviewEventRepository;
use App\Repository\PointsPolicyDecisionRepository;
use App\Security\PointsClaimVoter;
use App\Service\ImpactEvidenceProviderInterface;
use App\Service\PointsClaimService;
use App\Service\PointsLedgerService;
use App\Service\PointsPolicyRiskService;
use App\Service\PointsReasonLabelService;
use App\Service\RequestRateLimiterService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/points-claims')]
#[IsGranted('ROLE_USER')]
class PointsClaimController extends AbstractController
{
    #[Route('', name: 'points_claim_index', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        PointsClaimRepository $pointsClaimRepository,
        OfferRepository $offerRepository,
        PointsClaimService $pointsClaimService,
        PointsLedgerService $pointsLedgerService,
        PointsPolicyDecisionRepository $pointsPolicyDecisionRepository,
        PointsPolicyRiskService $pointsPolicyRiskService,
        PointsReasonLabelService $pointsReasonLabelService,
        RequestRateLimiterService $requestRateLimiterService,
        ImpactEvidenceProviderInterface $impactEvidenceProvider,
        EntityManagerInterface $entityManager,
        string $pointsClaimUploadDir,
    ): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$user->isCompany() || !$user->getCompany() instanceof Company) {
            $this->addFlash('error', 'Accès refusé : vous devez être connecté avec un compte entreprise.');
            return $this->redirectToRoute('home');
        }

        $company = $user->getCompany();
        $claim = (new PointsClaim())->setCompany($company);
        $offers = $offerRepository->findByAuthor((int) $user->getId());
        $retryClaim = null;
        $retryRemediationItems = [];

        $retryClaimId = max(0, (int) $request->query->get('retry_claim', 0));
        if ($retryClaimId > 0) {
            $candidateClaim = $pointsClaimRepository->find($retryClaimId);
            if (
                $candidateClaim instanceof PointsClaim
                && $candidateClaim->getCompany()?->getId() === $company->getId()
                && PointsClaim::STATUS_REJECTED === $candidateClaim->getStatus()
            ) {
                $retryClaim = $candidateClaim;
                $claim
                    ->setClaimType($candidateClaim->getClaimType())
                    ->setEvidenceIssuedAt($candidateClaim->getEvidenceIssuedAt());

                $retryOffer = $candidateClaim->getOffer();
                if ($retryOffer instanceof Offer && $this->offerBelongsToChoices($retryOffer, $offers)) {
                    $claim->setOffer($retryOffer);
                }

                $retryRemediationItems = $this->buildRemediationItems($candidateClaim, $company);
            }
        }

        $form = $this->createForm(PointsClaimType::class, $claim, [
            'offer_choices' => $offers,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<UploadedFile> $uploadedFiles */
            $uploadedFiles = $form->get('evidenceFiles')->getData() ?? [];
            if ([] === $uploadedFiles) {
                $form->get('evidenceFiles')->addError(new FormError('Ajoutez au moins un justificatif.'));
            } else {
                $requestRateLimiterService->consumePointsClaimSubmit($user);
                $riskSummary = $pointsPolicyRiskService->getCompanyRiskSummary($company);
                if (true === $riskSummary['cooldownActive']) {
                    $cooldownUntil = $riskSummary['cooldownUntil'];
                    $message = 'Pause de sécurité activée : après plusieurs refus automatiques, vos nouvelles demandes de points sont temporairement bloquées.';
                    if ($cooldownUntil instanceof \DateTimeImmutable) {
                        $message .= ' Vous pourrez réessayer à partir du ' . $cooldownUntil->format('d/m/Y H:i') . '.';
                    }

                    $this->addFlash('error', $message);

                    return $this->redirectToRoute('points_claim_index');
                }

                $fingerprints = [];
                foreach ($uploadedFiles as $uploadedFile) {
                    if ($uploadedFile instanceof UploadedFile) {
                        $fingerprints[] = hash_file('sha256', $uploadedFile->getPathname()) ?: $uploadedFile->getClientOriginalName();
                    }
                }

                $idempotencyKey = $this->buildIdempotencyKey($company, $claim, $fingerprints);
                $existingClaim = $pointsClaimRepository->findOneByIdempotencyKey($idempotencyKey);
                if ($existingClaim instanceof PointsClaim) {
                    $this->addFlash('info', 'Une preuve équivalente existe déjà. Redirection vers la demande existante.');
                    return $this->redirectToRoute('points_claim_show', ['id' => $existingClaim->getId()]);
                }

                if (!is_dir($pointsClaimUploadDir)) {
                    @mkdir($pointsClaimUploadDir, 0775, true);
                }

                $evidenceDocuments = [];
                foreach ($uploadedFiles as $uploadedFile) {
                    if (!$uploadedFile instanceof UploadedFile) {
                        continue;
                    }

                    $storedName = bin2hex(random_bytes(16));
                    $extension = $uploadedFile->guessExtension() ?: $uploadedFile->getClientOriginalExtension();
                    if ($extension) {
                        $storedName .= '.' . strtolower($extension);
                    }

                    try {
                        $uploadedFile->move($pointsClaimUploadDir, $storedName);
                    } catch (FileException) {
                        $this->addFlash('error', 'Impossible de téléverser une pièce justificative.');
                        return $this->redirectToRoute('points_claim_index');
                    }

                    $fullPath = rtrim($pointsClaimUploadDir, '/\\') . DIRECTORY_SEPARATOR . $storedName;
                    $evidenceDocuments[] = [
                        'originalName' => (string) $uploadedFile->getClientOriginalName(),
                        'storedName' => $storedName,
                        'mimeType' => (string) $uploadedFile->getClientMimeType(),
                        'size' => is_file($fullPath) ? (int) (filesize($fullPath) ?: 0) : 0,
                        'fileHash' => hash_file('sha256', $fullPath) ?: null,
                        'valid' => true,
                    ];
                }

                try {
                    $externalChecks = $this->buildExternalChecks($company, $claim, $impactEvidenceProvider);
                    $createdClaim = $pointsClaimService->submit(
                        company: $company,
                        claimType: $claim->getClaimType(),
                        evidenceDocuments: $evidenceDocuments,
                        idempotencyKey: $idempotencyKey,
                        offer: $claim->getOffer(),
                        evidenceIssuedAt: $claim->getEvidenceIssuedAt(),
                        externalChecks: $externalChecks,
                    );

                    $entityManager->flush();

                    if (PointsClaim::STATUS_APPROVED === $createdClaim->getStatus()) {
                        $this->addFlash('success', sprintf('Preuve validée automatiquement. +%d points.', (int) $createdClaim->getApprovedPoints()));
                    } elseif (PointsClaim::REASON_CODE_COOLDOWN_ACTIVE === $createdClaim->getDecisionReasonCode()) {
                        $this->addFlash('error', 'Pause de sécurité activée : après plusieurs refus automatiques, vos nouvelles demandes de points sont temporairement bloquées.');
                    } else {
                        $this->addFlash('error', (string) ($createdClaim->getDecisionReason() ?: 'Preuves insuffisantes. La demande a été rejetée.'));
                    }

                    return $this->redirectToRoute('points_claim_show', ['id' => $createdClaim->getId()]);
                } catch (\Throwable) {
                    $this->addFlash('error', 'Impossible de traiter vos preuves pour le moment.');
                }
            }
        }

        $claims = $pointsClaimRepository->findLatestForCompany((int) $company->getId());
        $pointsSummary = $pointsLedgerService->getCompanySummary($company);
        $policyPage = max(1, (int) $request->query->get('policy_page', 1));
        $policyPerPage = 10;
        $policyStatusFilter = strtoupper(trim((string) $request->query->get('policy_status', '')));
        $policyReferenceFilter = strtoupper(trim((string) $request->query->get('policy_reference', '')));
        $policyStatusFilter = '' !== $policyStatusFilter ? $policyStatusFilter : null;
        $policyReferenceFilter = '' !== $policyReferenceFilter ? $policyReferenceFilter : null;

        $policyDecisionTotal = $pointsPolicyDecisionRepository->countForCompanyFilters(
            (int) $company->getId(),
            $policyStatusFilter,
            $policyReferenceFilter,
        );
        $policyTotalPages = max(1, (int) ceil($policyDecisionTotal / $policyPerPage));
        $policyPage = min($policyPage, $policyTotalPages);
        $policyDecisions = $pointsPolicyDecisionRepository->findPageForCompany(
            (int) $company->getId(),
            $policyPage,
            $policyPerPage,
            $policyStatusFilter,
            $policyReferenceFilter,
        );
        $policyRiskSummary = $pointsPolicyRiskService->getCompanyRiskSummary($company);

        return $this->render('points_claim/index.html.twig', [
            'claims' => $claims,
            'company' => $company,
            'form' => $form->createView(),
            'impactPointsBalance' => $pointsSummary['balance'],
            'companyPointsHistory' => $pointsSummary['history'],
            'policyDecisions' => $policyDecisions,
            'policyPage' => $policyPage,
            'policyTotalPages' => $policyTotalPages,
            'policyStatusFilter' => $policyStatusFilter,
            'policyReferenceFilter' => $policyReferenceFilter,
            'policyRiskSummary' => $policyRiskSummary,
            'pointsReasonLabelService' => $pointsReasonLabelService,
            'retryClaim' => $retryClaim,
            'retryRemediationItems' => $retryRemediationItems,
        ]);
    }

    #[Route('/new', name: 'points_claim_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->redirectToRoute('points_claim_index');
    }

    #[Route('/{id}', name: 'points_claim_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(
        PointsClaim $claim,
        PointsClaimReviewEventRepository $reviewEventRepository,
        PointsReasonLabelService $pointsReasonLabelService,
    ): Response
    {
        $this->denyAccessUnlessGranted(PointsClaimVoter::VIEW, $claim);

        $events = null !== $claim->getId()
            ? $reviewEventRepository->findLatestForClaim((int) $claim->getId())
            : [];
        $claimCompany = $claim->getCompany();
        $remediationItems = $claimCompany instanceof Company
            ? $this->buildRemediationItems($claim, $claimCompany)
            : [];

        return $this->render('points_claim/show.html.twig', [
            'claim' => $claim,
            'events' => $events,
            'pointsReasonLabelService' => $pointsReasonLabelService,
            'remediationItems' => $remediationItems,
        ]);
    }

    #[Route('/{id}/evidence/{index}/download', name: 'points_claim_evidence_download', methods: ['GET'], requirements: ['id' => '\d+', 'index' => '\d+'])]
    public function downloadEvidence(PointsClaim $claim, int $index, string $pointsClaimUploadDir): Response
    {
        $this->denyAccessUnlessGranted(PointsClaimVoter::VIEW, $claim);

        $documents = $claim->getEvidenceDocuments();
        $document = $documents[$index] ?? null;
        if (!is_array($document) || !isset($document['storedName'])) {
            throw $this->createNotFoundException('Justificatif introuvable.');
        }

        $storedName = (string) $document['storedName'];
        if ($storedName !== basename($storedName)) {
            throw $this->createNotFoundException('Nom de fichier invalide.');
        }

        $fullPath = rtrim($pointsClaimUploadDir, '/\\') . DIRECTORY_SEPARATOR . $storedName;
        if (!is_file($fullPath)) {
            throw $this->createNotFoundException('Fichier introuvable.');
        }

        $downloadName = (string) ($document['originalName'] ?? 'justificatif');
        $response = new BinaryFileResponse($fullPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);

        return $response;
    }

    /**
     * @param list<Offer> $offerChoices
     */
    private function offerBelongsToChoices(Offer $selectedOffer, array $offerChoices): bool
    {
        $selectedOfferId = $selectedOffer->getId();
        if (null === $selectedOfferId) {
            return false;
        }

        foreach ($offerChoices as $offerChoice) {
            if (!$offerChoice instanceof Offer) {
                continue;
            }

            if ($offerChoice->getId() === $selectedOfferId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *   code: string,
     *   label: string,
     *   help: string,
     *   isPassed: bool,
     *   actionLabel: ?string,
     *   actionUrl: ?string
     * }>
     */
    private function buildRemediationItems(PointsClaim $claim, Company $company): array
    {
        $externalChecks = $claim->getExternalChecks();
        $coherence = is_array($externalChecks['coherence'] ?? null) ? $externalChecks['coherence'] : [];
        $criteria = is_array($coherence['criteria'] ?? null) ? $coherence['criteria'] : [];

        $companyEditUrl = null !== $company->getId()
            ? $this->generateUrl('company_edit', ['id' => $company->getId()])
            : null;
        $claimFormUrl = $this->generateUrl('points_claim_index') . '#claim-form';

        $definitions = [
            'profile_complete' => [
                'label' => 'Profil entreprise complet',
                'help' => 'Renseignez le site web, la ville et le secteur de votre entreprise.',
                'actionLabel' => 'Completer mon entreprise',
                'actionUrl' => $companyEditUrl,
            ],
            'offer_consistency' => [
                'label' => 'Offre coherente',
                'help' => "Selectionnez uniquement une offre appartenant a votre entreprise, ou laissez vide.",
                'actionLabel' => 'Verifier mon offre',
                'actionUrl' => $claimFormUrl,
            ],
            'evidence_date_valid' => [
                'label' => 'Date de preuve valide',
                'help' => 'Choisissez une date non future et datant de moins de 24 mois.',
                'actionLabel' => 'Corriger la date',
                'actionUrl' => $claimFormUrl,
            ],
            'supporting_documents_minimum' => [
                'label' => 'Au moins un justificatif exploitable',
                'help' => 'Ajoutez un ou plusieurs fichiers lisibles (PDF, image ou document).',
                'actionLabel' => 'Ajouter des justificatifs',
                'actionUrl' => $claimFormUrl,
            ],
        ];

        $items = [];
        foreach ($definitions as $code => $definition) {
            $items[] = [
                'code' => $code,
                'label' => $definition['label'],
                'help' => $definition['help'],
                'isPassed' => true === ($criteria[$code] ?? false),
                'actionLabel' => $definition['actionLabel'],
                'actionUrl' => $definition['actionUrl'],
            ];
        }

        return $items;
    }

    /**
     * @param list<string> $fingerprints
     */
    private function buildIdempotencyKey(Company $company, PointsClaim $claim, array $fingerprints): string
    {
        $payload = sprintf(
            '%d|%s|%d|%s',
            (int) $company->getId(),
            $claim->getClaimType(),
            (int) ($claim->getOffer()?->getId() ?? 0),
            implode(',', $fingerprints),
        );

        return 'pc_' . substr(hash('sha256', $payload), 0, 48);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildExternalChecks(
        Company $company,
        PointsClaim $claim,
        ImpactEvidenceProviderInterface $impactEvidenceProvider,
    ): array {
        $offer = $claim->getOffer();
        $checks = [
            'hasCompanyWebsite' => null !== $company->getWebsite() && '' !== trim((string) $company->getWebsite()),
            'hasCompanyCity' => null !== $company->getCity() && '' !== trim((string) $company->getCity()),
            'hasCompanySector' => null !== $company->getSector() && '' !== trim((string) $company->getSector()),
            'offerLinked' => $offer instanceof \App\Entity\Offer,
        ];

        $sameCompanyOffer = false;
        $sources = [];

        if ($offer instanceof \App\Entity\Offer) {
            $offerCompany = $offer->getCompany();
            $sameCompanyOffer = $offerCompany instanceof Company && $offerCompany->getId() === $company->getId();
            $checks['sameCompanyOffer'] = $sameCompanyOffer;

            try {
                $evidence = $impactEvidenceProvider->collectForOffer($offer);
                $companyEvidence = is_array($evidence['company'] ?? null) ? $evidence['company'] : [];
                $locationEvidence = is_array($evidence['location'] ?? null) ? $evidence['location'] : [];

                $checks['apiCompanyFound'] = true === ($companyEvidence['found'] ?? false);
                $checks['apiCompanyActive'] = true === ($companyEvidence['active'] ?? false);
                $checks['apiLocationValidated'] = true === ($locationEvidence['validated'] ?? false);
                $sources = is_array($evidence['sources'] ?? null) ? $evidence['sources'] : [];
            } catch (\Throwable) {
                $checks['apiReachable'] = false;
            }
        }

        $profileComplete = true === ($checks['hasCompanyWebsite'] ?? null)
            && true === ($checks['hasCompanyCity'] ?? null)
            && true === ($checks['hasCompanySector'] ?? null);
        $offerConsistency = false === ($checks['offerLinked'] ?? false) || true === ($checks['sameCompanyOffer'] ?? false);

        return [
            'coherenceOk' => $profileComplete && $offerConsistency,
            'checks' => $checks,
            'sources' => $sources,
        ];
    }
}
