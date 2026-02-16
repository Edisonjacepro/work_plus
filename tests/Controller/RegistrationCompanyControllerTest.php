<?php

namespace App\Tests\Controller;

use App\Entity\Company;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class RegistrationCompanyControllerTest extends WebTestCase
{
    public function testCompanyRegistrationPageContainsNewCompanyFieldsInFrench(): void
    {
        $client = static::createClient();
        $client->request('GET', '/register/company');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Nom de l&#039;entreprise', $content);
        self::assertStringContainsString('Description de l&#039;entreprise', $content);
        self::assertStringContainsString('Site web', $content);
        self::assertStringContainsString('Ville', $content);
        self::assertStringContainsString('Secteur', $content);
        self::assertStringContainsString('Taille de l&#039;entreprise', $content);
        self::assertStringNotContainsString('Entreprise existante', $content);
    }

    public function testCompanyAndEmailConflictsAreDisplayedInSingleSubmit(): void
    {
        $client = static::createClient();
        /** @var EntityManagerInterface $entityManager */
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        try {
            $entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable) {
            self::markTestSkipped('Connexion base de test indisponible pour ce test fonctionnel.');
        }

        $suffix = bin2hex(random_bytes(4));
        $existingCompanyName = 'Entreprise Test ' . $suffix;
        $existingEmail = 'recruteur-' . $suffix . '@example.test';

        $company = (new Company())->setName($existingCompanyName);
        $user = (new User())
            ->setEmail($existingEmail)
            ->setAccountType(User::ACCOUNT_TYPE_COMPANY)
            ->setCompany($company)
            ->setPassword('hashed-password');

        $entityManager->persist($company);
        $entityManager->persist($user);
        $entityManager->flush();

        $crawler = $client->request('GET', '/register/company');
        $form = $crawler->selectButton('Creer le compte')->form([
            'registration_company[email]' => $existingEmail,
            'registration_company[plainPassword]' => 'Password123!',
            'registration_company[companyName]' => $existingCompanyName,
            'registration_company[description]' => 'Description de test',
            'registration_company[website]' => '',
            'registration_company[city]' => 'Paris',
            'registration_company[sector]' => 'ESS',
            'registration_company[companySize]' => '1-10',
        ]);

        $client->submit($form);
        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Le nom de cette entreprise est deja utilise.', $content);
        self::assertStringContainsString('Cet email est deja utilise.', $content);
    }
}
