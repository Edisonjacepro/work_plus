<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\User;
use App\Form\PointsClaimType;
use App\Repository\OfferRepository;
use App\Repository\PointsClaimRepository;
use App\Repository\PointsClaimReviewEventRepository;
use App\Security\PointsClaimVoter;
use App\Service\ImpactEvidenceProviderInterface;
use App\Service\PointsClaimService;
use App\Service\PointsLedgerService;
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
            $this->addFlash('error', 'Acces refuse : vous devez etre connecte avec un compte entreprise.');
            return $this->redirectToRoute('home');
        }

        $company = $user->getCompany();
        $claim = (new PointsClaim())->setCompany($company);
        $offers = $offerRepository->findByAuthor((int) $user->getId());

        $form = $this->createForm(PointsClaimType::class, $claim, [
            'offer_choices' => $offers,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var list<UploadedFile> $uploadedFiles */
            $uploadedFiles = $form->get('evidenceFiles')->getData() ?? [];
            if ([] === $uploadedFiles) {
                $form->get('evidenceFiles')->addError(new FormError('Au moins un justificatif est requis.'));
            } else {
                $fingerprints = [];
                foreach ($uploadedFiles as $uploadedFile) {
                    if ($uploadedFile instanceof UploadedFile) {
                        $fingerprints[] = hash_file('sha256', $uploadedFile->getPathname()) ?: $uploadedFile->getClientOriginalName();
                    }
                }

                $idempotencyKey = $this->buildIdempotencyKey($company, $claim, $fingerprints);
                $existingClaim = $pointsClaimRepository->findOneByIdempotencyKey($idempotencyKey);
                if ($existingClaim instanceof PointsClaim) {
                    $this->addFlash('info', 'Une preuve equivalente existe deja. Redirection vers la demande existante.');
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
                        $this->addFlash('error', 'Impossible de televerser une piece justificative.');
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
                        $this->addFlash('success', sprintf('Preuve validee automatiquement. +%d points.', (int) $createdClaim->getApprovedPoints()));
                    } else {
                        $this->addFlash('error', 'Preuves insuffisantes. La demande a ete rejetee.');
                    }

                    return $this->redirectToRoute('points_claim_show', ['id' => $createdClaim->getId()]);
                } catch (\Throwable) {
                    $this->addFlash('error', 'Impossible de traiter vos preuves pour le moment.');
                }
            }
        }

        $claims = $pointsClaimRepository->findLatestForCompany((int) $company->getId());
        $pointsSummary = $pointsLedgerService->getCompanySummary($company);

        return $this->render('points_claim/index.html.twig', [
            'claims' => $claims,
            'company' => $company,
            'form' => $form->createView(),
            'impactPointsBalance' => $pointsSummary['balance'],
            'companyPointsHistory' => $pointsSummary['history'],
        ]);
    }

    #[Route('/new', name: 'points_claim_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->redirectToRoute('points_claim_index');
    }

    #[Route('/{id}', name: 'points_claim_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(PointsClaim $claim, PointsClaimReviewEventRepository $reviewEventRepository): Response
    {
        $this->denyAccessUnlessGranted(PointsClaimVoter::VIEW, $claim);

        $events = null !== $claim->getId()
            ? $reviewEventRepository->findLatestForClaim((int) $claim->getId())
            : [];

        return $this->render('points_claim/show.html.twig', [
            'claim' => $claim,
            'events' => $events,
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

        $sameCompanyOffer = true;
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

        return [
            'coherenceOk' => $sameCompanyOffer,
            'checks' => $checks,
            'sources' => $sources,
        ];
    }
}
