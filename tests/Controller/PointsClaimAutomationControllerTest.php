<?php

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\PointsClaimReviewEvent;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use App\Repository\PointsClaimRepository;
use App\Repository\PointsClaimReviewEventRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class PointsClaimAutomationControllerTest extends WebTestCase
{
    public function testSubmitPointsClaimAutoApprovesAndCreditsLedger(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        [$company, $user] = $this->createCompanyUser($entityManager, true);
        $entityManager->flush();

        $client->loginUser($user);
        $token = $this->fetchPointsClaimToken($client);

        $filePaths = $this->createTempEvidenceFiles(['proof-a', 'proof-b', 'proof-c']);
        $this->submitPointsClaim($client, $token, PointsClaim::CLAIM_TYPE_TRAINING, $filePaths, '2026-02-20');
        self::assertResponseStatusCodeSame(302);

        $entityManager->clear();

        /** @var Company|null $savedCompany */
        $savedCompany = $entityManager->getRepository(Company::class)->find($company->getId());
        self::assertInstanceOf(Company::class, $savedCompany);

        /** @var PointsClaimRepository $claimRepository */
        $claimRepository = $entityManager->getRepository(PointsClaim::class);
        $claims = $claimRepository->findLatestForCompany((int) $savedCompany->getId(), 10);
        self::assertCount(1, $claims);

        $claim = $claims[0];
        self::assertSame(PointsClaim::STATUS_APPROVED, $claim->getStatus());
        self::assertGreaterThanOrEqual(70, $claim->getEvidenceScore());
        self::assertGreaterThan(0, (int) $claim->getApprovedPoints());

        $ledgerEntries = $entityManager->getRepository(PointsLedgerEntry::class)->findBy([
            'company' => $savedCompany,
            'referenceType' => PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
        ]);
        self::assertCount(1, $ledgerEntries);

        /** @var PointsClaimReviewEventRepository $reviewEventRepository */
        $reviewEventRepository = $entityManager->getRepository(PointsClaimReviewEvent::class);
        $events = $reviewEventRepository->findLatestForClaim((int) $claim->getId(), 10);
        self::assertCount(2, $events);
        self::assertSame(PointsClaimReviewEvent::ACTION_AUTO_APPROVED, $events[0]->getAction());
    }

    public function testSubmitPointsClaimAutoRejectsWithoutEnoughEvidence(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        [$company, $user] = $this->createCompanyUser($entityManager, false);
        $entityManager->flush();

        $client->loginUser($user);
        $token = $this->fetchPointsClaimToken($client);

        $filePaths = $this->createTempEvidenceFiles(['single-proof']);
        $this->submitPointsClaim($client, $token, PointsClaim::CLAIM_TYPE_OTHER, $filePaths, '2026-02-20');
        self::assertResponseStatusCodeSame(302);

        $entityManager->clear();

        /** @var Company|null $savedCompany */
        $savedCompany = $entityManager->getRepository(Company::class)->find($company->getId());
        self::assertInstanceOf(Company::class, $savedCompany);

        /** @var PointsClaimRepository $claimRepository */
        $claimRepository = $entityManager->getRepository(PointsClaim::class);
        $claims = $claimRepository->findLatestForCompany((int) $savedCompany->getId(), 10);
        self::assertCount(1, $claims);

        $claim = $claims[0];
        self::assertSame(PointsClaim::STATUS_REJECTED, $claim->getStatus());
        self::assertNull($claim->getApprovedPoints());
        self::assertSame(PointsClaim::REASON_CODE_INSUFFICIENT_EVIDENCE_SCORE, $claim->getDecisionReasonCode());

        $ledgerEntries = $entityManager->getRepository(PointsLedgerEntry::class)->findBy([
            'company' => $savedCompany,
            'referenceType' => PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
        ]);
        self::assertCount(0, $ledgerEntries);
    }

    public function testSubmitPointsClaimIsIdempotentForSameEvidencePayload(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        [$company, $user] = $this->createCompanyUser($entityManager, true);
        $entityManager->flush();

        $client->loginUser($user);
        $token = $this->fetchPointsClaimToken($client);

        $firstBatch = $this->createTempEvidenceFiles(['same-a', 'same-b', 'same-c']);
        $this->submitPointsClaim($client, $token, PointsClaim::CLAIM_TYPE_CERTIFICATION, $firstBatch, '2026-02-20');
        self::assertResponseStatusCodeSame(302);

        $entityManager->clear();
        /** @var Company|null $savedCompany */
        $savedCompany = $entityManager->getRepository(Company::class)->find($company->getId());
        self::assertInstanceOf(Company::class, $savedCompany);

        /** @var PointsClaimRepository $claimRepository */
        $claimRepository = $entityManager->getRepository(PointsClaim::class);
        $firstClaims = $claimRepository->findLatestForCompany((int) $savedCompany->getId(), 10);
        self::assertCount(1, $firstClaims);
        $firstClaimId = (int) $firstClaims[0]->getId();

        $token = $this->fetchPointsClaimToken($client);
        $secondBatch = $this->createTempEvidenceFiles(['same-a', 'same-b', 'same-c']);
        $this->submitPointsClaim($client, $token, PointsClaim::CLAIM_TYPE_CERTIFICATION, $secondBatch, '2026-02-20');
        self::assertResponseRedirects('/points-claims/' . $firstClaimId);

        $entityManager->clear();
        /** @var Company|null $savedCompanyAfterSecond */
        $savedCompanyAfterSecond = $entityManager->getRepository(Company::class)->find($company->getId());
        self::assertInstanceOf(Company::class, $savedCompanyAfterSecond);

        $claimsAfterSecondSubmit = $claimRepository->findLatestForCompany((int) $savedCompanyAfterSecond->getId(), 10);
        self::assertCount(1, $claimsAfterSecondSubmit);

        $ledgerEntries = $entityManager->getRepository(PointsLedgerEntry::class)->findBy([
            'company' => $savedCompanyAfterSecond,
            'referenceType' => PointsLedgerEntry::REFERENCE_POINTS_CLAIM_APPROVAL,
        ]);
        self::assertCount(1, $ledgerEntries);
    }

    private function fetchPointsClaimToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/points-claims');
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('input[name="points_claim[_token]"]')->attr('value');
        self::assertNotNull($token);

        return (string) $token;
    }

    /**
     * @param list<string> $filePaths
     */
    private function submitPointsClaim(
        KernelBrowser $client,
        string $token,
        string $claimType,
        array $filePaths,
        string $evidenceIssuedAt,
    ): void {
        $uploadedFiles = [];
        foreach ($filePaths as $index => $filePath) {
            $uploadedFiles[] = new UploadedFile(
                $filePath,
                sprintf('proof-%d.txt', $index + 1),
                'text/plain',
                null,
                true,
            );
        }

        $client->request('POST', '/points-claims', [
            'points_claim' => [
                'claimType' => $claimType,
                'offer' => '',
                'evidenceIssuedAt' => $evidenceIssuedAt,
                '_token' => $token,
            ],
        ], [
            'points_claim' => [
                'evidenceFiles' => $uploadedFiles,
            ],
        ]);
    }

    /**
     * @param list<string> $contents
     * @return list<string>
     */
    private function createTempEvidenceFiles(array $contents): array
    {
        $paths = [];
        foreach ($contents as $content) {
            $path = tempnam(sys_get_temp_dir(), 'wp-proof-');
            if (false === $path) {
                throw new \RuntimeException('Cannot create temporary evidence file.');
            }

            file_put_contents($path, $content);
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(EntityManagerInterface $entityManager, bool $withProfileData): array
    {
        $suffix = bin2hex(random_bytes(4));
        $company = (new Company())
            ->setName('Points Company ' . $suffix)
            ->setDescription('Entreprise de test');

        if ($withProfileData) {
            $company
                ->setWebsite('https://company-' . $suffix . '.example.test')
                ->setCity('Lyon')
                ->setSector('ESS');
        }

        $user = (new User())
            ->setEmail('points-recruiter-' . $suffix . '@example.test')
            ->setPassword('test-password')
            ->setAccountType(User::ACCOUNT_TYPE_COMPANY)
            ->setCompany($company);

        $entityManager->persist($company);
        $entityManager->persist($user);

        return [$company, $user];
    }

    private function requireDatabaseOrSkip(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);

        try {
            $entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Connexion base de test indisponible pour ce test fonctionnel.');
        }

        return $entityManager;
    }
}
