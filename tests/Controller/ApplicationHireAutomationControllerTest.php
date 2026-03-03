<?php

namespace App\Tests\Controller;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\Offer;
use App\Entity\PointsLedgerEntry;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApplicationHireAutomationControllerTest extends WebTestCase
{
    public function testRecruiterCanHireCandidateAndCreditPointsIdempotently(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        [$company, $recruiter, $candidate, $application] = $this->createFixture($entityManager);
        $entityManager->flush();

        $applicationId = (int) $application->getId();

        $client->loginUser($recruiter);
        $crawler = $client->request('GET', '/applications/' . $applicationId);
        self::assertResponseIsSuccessful();

        $token = $crawler->filter('form[action="/applications/' . $applicationId . '/hire"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request('POST', '/applications/' . $applicationId . '/hire', [
            '_token' => (string) $token,
        ]);
        self::assertResponseRedirects('/applications/' . $applicationId);

        $entityManager->clear();

        /** @var Application|null $savedApplication */
        $savedApplication = $entityManager->getRepository(Application::class)->find($applicationId);
        self::assertInstanceOf(Application::class, $savedApplication);
        self::assertSame(Application::STATUS_HIRED, $savedApplication->getStatus());
        self::assertNotNull($savedApplication->getHiredAt());

        /** @var User|null $savedCandidate */
        $savedCandidate = $entityManager->getRepository(User::class)->find($candidate->getId());
        self::assertInstanceOf(User::class, $savedCandidate);

        $entriesAfterFirstHire = $entityManager->getRepository(PointsLedgerEntry::class)->findBy([
            'user' => $savedCandidate,
            'referenceType' => PointsLedgerEntry::REFERENCE_APPLICATION_HIRED,
            'referenceId' => $applicationId,
        ]);
        self::assertCount(1, $entriesAfterFirstHire);
        self::assertGreaterThan(0, (int) $entriesAfterFirstHire[0]->getPoints());

        // Second click must not create any duplicate credit.
        $client->request('POST', '/applications/' . $applicationId . '/hire', [
            '_token' => (string) $token,
        ]);
        self::assertResponseRedirects('/applications/' . $applicationId);

        $entityManager->clear();
        /** @var User|null $savedCandidateAfterSecondHire */
        $savedCandidateAfterSecondHire = $entityManager->getRepository(User::class)->find($candidate->getId());
        self::assertInstanceOf(User::class, $savedCandidateAfterSecondHire);

        $entriesAfterSecondHire = $entityManager->getRepository(PointsLedgerEntry::class)->findBy([
            'user' => $savedCandidateAfterSecondHire,
            'referenceType' => PointsLedgerEntry::REFERENCE_APPLICATION_HIRED,
            'referenceId' => $applicationId,
        ]);
        self::assertCount(1, $entriesAfterSecondHire);

        $client->loginUser($savedCandidateAfterSecondHire);
        $client->request('GET', '/applications/me');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Candidature embauchée', $html);
        self::assertStringContainsString('Points candidat attribués après embauche', $html);
    }

    /**
     * @return array{0: Company, 1: User, 2: User, 3: Application}
     */
    private function createFixture(EntityManagerInterface $entityManager): array
    {
        $suffix = bin2hex(random_bytes(4));

        $company = (new Company())
            ->setName('Hire Flow Company ' . $suffix)
            ->setDescription('Entreprise de test pour parcours embauche')
            ->setWebsite('https://hire-flow-' . $suffix . '.example.test')
            ->setCity('Paris')
            ->setSector('ESS');

        $recruiter = (new User())
            ->setEmail('hire-recruiter-' . $suffix . '@example.test')
            ->setPassword('test-password')
            ->setAccountType(User::ACCOUNT_TYPE_COMPANY)
            ->setCompany($company);

        $candidate = (new User())
            ->setEmail('hire-candidate-' . $suffix . '@example.test')
            ->setPassword('test-password')
            ->setAccountType(User::ACCOUNT_TYPE_PERSON);

        $offer = (new Offer())
            ->setTitle('Mission impact ' . $suffix)
            ->setDescription(str_repeat('Mission orientée impact positif et utilité sociale. ', 3))
            ->setImpactCategories(['societe'])
            ->setCompany($company)
            ->setAuthor($recruiter);

        $application = (new Application())
            ->setOffer($offer)
            ->setCandidate($candidate)
            ->setEmail($candidate->getEmail() ?? 'candidate@example.test')
            ->setFirstName('Test')
            ->setLastName('Candidate')
            ->setMessage('Je souhaite rejoindre cette mission a impact.');

        $entityManager->persist($company);
        $entityManager->persist($recruiter);
        $entityManager->persist($candidate);
        $entityManager->persist($offer);
        $entityManager->persist($application);

        return [$company, $recruiter, $candidate, $application];
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
