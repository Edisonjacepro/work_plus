<?php

namespace App\Tests\Controller;

use App\Entity\Application;
use App\Entity\Company;
use App\Entity\Offer;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PaginationControllerTest extends WebTestCase
{
    public function testCompanyIndexIsPaginated(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        $suffix = bin2hex(random_bytes(4));
        for ($i = 1; $i <= 15; ++$i) {
            $company = (new Company())
                ->setName(sprintf('Pagination Company %s %02d', $suffix, $i))
                ->setDescription('Pagination test');
            $entityManager->persist($company);
        }
        $entityManager->flush();

        $client->request('GET', '/companies?page=1');
        self::assertResponseIsSuccessful();
        $contentPage1 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Pagination Company %s 15', $suffix), $contentPage1);
        self::assertStringNotContainsString(sprintf('Pagination Company %s 03', $suffix), $contentPage1);

        $client->request('GET', '/companies?page=2');
        self::assertResponseIsSuccessful();
        $contentPage2 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Pagination Company %s 03', $suffix), $contentPage2);
        self::assertStringContainsString('Page 2 /', $contentPage2);
    }

    public function testOfferIndexIsPaginated(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        $suffix = bin2hex(random_bytes(4));
        $company = (new Company())
            ->setName('Pagination Offer Company ' . $suffix)
            ->setDescription('Pagination test');
        $author = (new User())
            ->setEmail('offer-pagination-' . $suffix . '@example.test')
            ->setPassword('test-password')
            ->setAccountType(User::ACCOUNT_TYPE_COMPANY)
            ->setCompany($company);

        $entityManager->persist($company);
        $entityManager->persist($author);
        $entityManager->flush();

        for ($i = 1; $i <= 15; ++$i) {
            $offer = (new Offer())
                ->setTitle(sprintf('Pagination Offer %s %02d', $suffix, $i))
                ->setDescription('Description de test pour pagination des offres.')
                ->setImpactCategories(['societe'])
                ->setCompany($company)
                ->setAuthor($author)
                ->setStatus(Offer::STATUS_PUBLISHED)
                ->setModerationStatus(Offer::MODERATION_STATUS_APPROVED)
                ->setIsVisible(true)
                ->setPublishedAt((new \DateTimeImmutable())->modify('+' . $i . ' seconds'));
            $entityManager->persist($offer);
        }
        $entityManager->flush();

        $client->request('GET', '/offers?page=1');
        self::assertResponseIsSuccessful();
        $contentPage1 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Pagination Offer %s 15', $suffix), $contentPage1);
        self::assertStringNotContainsString(sprintf('Pagination Offer %s 03', $suffix), $contentPage1);

        $client->request('GET', '/offers?page=2');
        self::assertResponseIsSuccessful();
        $contentPage2 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Pagination Offer %s 03', $suffix), $contentPage2);
    }

    public function testRecruiterApplicationsIndexIsPaginated(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        $suffix = bin2hex(random_bytes(4));
        $company = (new Company())
            ->setName('Pagination Recruiter Company ' . $suffix)
            ->setDescription('Pagination test');
        $recruiter = (new User())
            ->setEmail('recruiter-pagination-' . $suffix . '@example.test')
            ->setPassword('test-password')
            ->setAccountType(User::ACCOUNT_TYPE_COMPANY)
            ->setCompany($company);
        $offer = (new Offer())
            ->setTitle('Offer recruiter pagination ' . $suffix)
            ->setDescription('Description')
            ->setImpactCategories(['societe'])
            ->setCompany($company)
            ->setAuthor($recruiter);

        $entityManager->persist($company);
        $entityManager->persist($recruiter);
        $entityManager->persist($offer);
        $entityManager->flush();

        for ($i = 1; $i <= 25; ++$i) {
            $application = (new Application())
                ->setOffer($offer)
                ->setEmail(sprintf('app-pagination-%s-%02d@example.test', $suffix, $i))
                ->setFirstName(sprintf('Candidate%s%02d', $suffix, $i))
                ->setLastName('Test')
                ->setMessage('Message de test');
            $entityManager->persist($application);
        }
        $entityManager->flush();

        $client->loginUser($recruiter);
        $client->request('GET', '/applications/recruiter?page=1');
        self::assertResponseIsSuccessful();
        $contentPage1 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Candidate%s25', $suffix), $contentPage1);
        self::assertStringNotContainsString(sprintf('Candidate%s05', $suffix), $contentPage1);

        $client->request('GET', '/applications/recruiter?page=2');
        self::assertResponseIsSuccessful();
        $contentPage2 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Candidate%s05', $suffix), $contentPage2);
        self::assertStringContainsString('Page 2 /', $contentPage2);
    }

    public function testCandidateApplicationsIndexIsPaginated(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        $suffix = bin2hex(random_bytes(4));
        $company = (new Company())
            ->setName('Pagination Candidate Company ' . $suffix)
            ->setDescription('Pagination test');
        $recruiter = (new User())
            ->setEmail('candidate-recruiter-' . $suffix . '@example.test')
            ->setPassword('test-password')
            ->setAccountType(User::ACCOUNT_TYPE_COMPANY)
            ->setCompany($company);
        $candidate = (new User())
            ->setEmail('candidate-pagination-' . $suffix . '@example.test')
            ->setPassword('test-password')
            ->setAccountType(User::ACCOUNT_TYPE_PERSON)
            ->setFirstName('Candidate')
            ->setLastName('Pagination');
        $entityManager->persist($company);
        $entityManager->persist($recruiter);
        $entityManager->persist($candidate);
        $entityManager->flush();

        for ($i = 1; $i <= 25; ++$i) {
            $offer = (new Offer())
                ->setTitle(sprintf('Offer candidate pagination %s %02d', $suffix, $i))
                ->setDescription('Description')
                ->setImpactCategories(['societe'])
                ->setCompany($company)
                ->setAuthor($recruiter);

            $application = (new Application())
                ->setOffer($offer)
                ->setCandidate($candidate)
                ->setEmail('candidate-pagination-' . $suffix . '@example.test')
                ->setFirstName('Candidate')
                ->setLastName('Pagination')
                ->setMessage(sprintf('Message candidat %02d', $i));
            $entityManager->persist($offer);
            $entityManager->persist($application);
        }
        $entityManager->flush();

        $client->loginUser($candidate);
        $client->request('GET', '/applications/me?page=1');
        self::assertResponseIsSuccessful();
        $contentPage1 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Offer candidate pagination %s %02d', $suffix, 25), $contentPage1);
        self::assertStringNotContainsString(sprintf('Offer candidate pagination %s %02d', $suffix, 5), $contentPage1);

        $client->request('GET', '/applications/me?page=2');
        self::assertResponseIsSuccessful();
        $contentPage2 = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(sprintf('Offer candidate pagination %s %02d', $suffix, 5), $contentPage2);
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
