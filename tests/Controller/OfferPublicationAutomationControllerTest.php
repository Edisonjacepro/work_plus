<?php

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\ModerationReview;
use App\Entity\Offer;
use App\Entity\User;
use App\Repository\ModerationReviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OfferPublicationAutomationControllerTest extends WebTestCase
{
    public function testPublishOfferAutoRejectsWhenImpactScoreIsTooLow(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        [$company, $user] = $this->createCompanyUser($entityManager, true);
        $offer = (new Offer())
            ->setTitle('Programme climat biodiversite inclusion')
            ->setDescription(str_repeat('Nous reduisons les emissions, protegeons la biodiversite et creons des emplois inclusifs. ', 5))
            ->setImpactCategories(['societe', 'biodiversite', 'ges'])
            ->setCompany($company)
            ->setAuthor($user);

        $entityManager->persist($offer);
        $entityManager->flush();

        $client->loginUser($user);
        $this->publishOffer($client, (int) $offer->getId());
        self::assertResponseRedirects('/offers/' . $offer->getId());

        $entityManager->clear();

        /** @var Offer|null $savedOffer */
        $savedOffer = $entityManager->getRepository(Offer::class)->find($offer->getId());
        self::assertInstanceOf(Offer::class, $savedOffer);
        self::assertSame(Offer::STATUS_DRAFT, $savedOffer->getStatus());
        self::assertSame(Offer::MODERATION_STATUS_REJECTED, $savedOffer->getModerationStatus());
        self::assertSame('LOW_IMPACT_SCORE', $savedOffer->getModerationReasonCode());
        self::assertNull($savedOffer->getPublishedAt());
        self::assertNotNull($savedOffer->getModeratedAt());
        self::assertFalse($savedOffer->isVisible());
    }

    public function testPublishOfferAutoRejectsWhenForbiddenKeywordIsDetected(): void
    {
        $client = static::createClient();
        $entityManager = $this->requireDatabaseOrSkip();

        [$company, $user] = $this->createCompanyUser($entityManager, false);
        $offer = (new Offer())
            ->setTitle('Offre locale')
            ->setDescription(str_repeat('Description longue pour la moderation automatique. ', 3) . 'violence')
            ->setImpactCategories(['societe'])
            ->setCompany($company)
            ->setAuthor($user);

        $entityManager->persist($offer);
        $entityManager->flush();

        $client->loginUser($user);
        $this->publishOffer($client, (int) $offer->getId());
        self::assertResponseRedirects('/offers/' . $offer->getId());

        $entityManager->clear();

        /** @var Offer|null $savedOffer */
        $savedOffer = $entityManager->getRepository(Offer::class)->find($offer->getId());
        self::assertInstanceOf(Offer::class, $savedOffer);
        self::assertSame(Offer::STATUS_DRAFT, $savedOffer->getStatus());
        self::assertSame(Offer::MODERATION_STATUS_REJECTED, $savedOffer->getModerationStatus());
        self::assertSame('FORBIDDEN_ACTIVITY', $savedOffer->getModerationReasonCode());
        self::assertFalse($savedOffer->isVisible());

        /** @var ModerationReviewRepository $reviewRepository */
        $reviewRepository = $entityManager->getRepository(ModerationReview::class);
        $reviews = $reviewRepository->findBy(['offer' => $savedOffer], ['id' => 'ASC']);
        self::assertCount(2, $reviews);
        self::assertSame(ModerationReview::ACTION_SUBMITTED, $reviews[0]->getAction());
        self::assertSame(ModerationReview::ACTION_AUTO_REJECTED, $reviews[1]->getAction());
    }

    private function publishOffer(KernelBrowser $client, int $offerId): void
    {
        $crawler = $client->request('GET', '/offers/' . $offerId);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('form[action="/offers/' . $offerId . '/publish"] input[name="_token"]')->attr('value');
        self::assertNotNull($token);

        $client->request('POST', '/offers/' . $offerId . '/publish', [
            '_token' => (string) $token,
        ]);
    }

    /**
     * @return array{0: Company, 1: User}
     */
    private function createCompanyUser(EntityManagerInterface $entityManager, bool $withProfileData): array
    {
        $suffix = bin2hex(random_bytes(4));
        $company = (new Company())
            ->setName('Company Auto ' . $suffix)
            ->setDescription('Entreprise de test');

        if ($withProfileData) {
            $company
                ->setWebsite('https://example.test/' . $suffix)
                ->setCity('Paris')
                ->setSector('ESS');
        }

        $user = (new User())
            ->setEmail('recruiter-' . $suffix . '@example.test')
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
