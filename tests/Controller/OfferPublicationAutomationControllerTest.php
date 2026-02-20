<?php

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\ModerationReview;
use App\Entity\Offer;
use App\Entity\User;
use App\Repository\ImpactScoreRepository;
use App\Repository\ModerationReviewRepository;
use App\Service\ImpactEvidenceProviderInterface;
use App\Service\ImpactScoringService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class OfferPublicationAutomationControllerTest extends WebTestCase
{
    public function testPublishOfferAutoApprovesWhenEligibilityScoreIsHigh(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $entityManager = $this->requireDatabaseOrSkip();

        $provider = new class() implements ImpactEvidenceProviderInterface {
            public function collectForOffer(Offer $offer): array
            {
                return [
                    'company' => [
                        'checked' => true,
                        'found' => true,
                        'active' => true,
                        'isEss' => true,
                        'isMissionCompany' => false,
                        'hasGesReport' => true,
                    ],
                    'location' => [
                        'checked' => true,
                        'validated' => true,
                    ],
                ];
            }
        };
        $container->set(ImpactScoringService::class, new ImpactScoringService($provider));

        [$company, $user] = $this->createCompanyUser($entityManager, true);
        $offer = (new Offer())
            ->setTitle('Programme climat biodiversite inclusion')
            ->setDescription(str_repeat('Nous reduisons les emissions, protegeons la biodiversite et creons des emplois inclusifs. ', 3))
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
        self::assertSame(Offer::STATUS_PUBLISHED, $savedOffer->getStatus());
        self::assertSame(Offer::MODERATION_STATUS_APPROVED, $savedOffer->getModerationStatus());
        self::assertNotNull($savedOffer->getPublishedAt());
        self::assertNotNull($savedOffer->getModeratedAt());
        self::assertTrue($savedOffer->isVisible());

        /** @var ModerationReviewRepository $reviewRepository */
        $reviewRepository = $entityManager->getRepository(ModerationReview::class);
        $reviews = $reviewRepository->findBy(['offer' => $savedOffer], ['id' => 'ASC']);
        self::assertCount(2, $reviews);
        self::assertSame(ModerationReview::ACTION_SUBMITTED, $reviews[0]->getAction());
        self::assertSame(ModerationReview::ACTION_AUTO_APPROVED, $reviews[1]->getAction());

        /** @var ImpactScoreRepository $impactScoreRepository */
        $impactScoreRepository = $entityManager->getRepository(\App\Entity\ImpactScore::class);
        self::assertNotNull($impactScoreRepository->findLatestForOffer((int) $savedOffer->getId()));
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
        /** @var CsrfTokenManagerInterface $csrfTokenManager */
        $csrfTokenManager = static::getContainer()->get(CsrfTokenManagerInterface::class);
        $token = $csrfTokenManager->getToken('publish_offer_' . $offerId)->getValue();

        $client->request('POST', '/offers/' . $offerId . '/publish', [
            '_token' => $token,
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
