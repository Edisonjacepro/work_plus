<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Form\RegistrationCompanyType;
use App\Form\RegistrationPersonType;
use App\Repository\CompanyRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

class RegistrationController extends AbstractController
{
    #[Route('/register/company', name: 'app_register_company')]
    public function registerCompany(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        CompanyRepository $companyRepository,
        UserRepository $userRepository
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }

        $form = $this->createForm(RegistrationCompanyType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $companyName = trim((string) ($data['companyName'] ?? ''));
            $email = trim((string) ($data['email'] ?? ''));
            $hasBlockingError = false;

            if ($companyRepository->findOneBy(['name' => $companyName]) instanceof Company) {
                $message = 'Le nom de cette entreprise est deja utilise.';
                $form->get('companyName')->addError(new FormError($message));
                $hasBlockingError = true;
            }

            if ($userRepository->existsByEmailInsensitive($email)) {
                $message = 'Cet email est deja utilise.';
                $form->get('email')->addError(new FormError($message));
                $hasBlockingError = true;
            }

            if (!$hasBlockingError) {
                $company = new Company();
                $company
                    ->setName($companyName)
                    ->setDescription(self::nullableTrim($data['description'] ?? null))
                    ->setWebsite(self::nullableTrim($data['website'] ?? null))
                    ->setCity(self::nullableTrim($data['city'] ?? null))
                    ->setSector(self::nullableTrim($data['sector'] ?? null))
                    ->setCompanySize(self::nullableTrim($data['companySize'] ?? null));

                $user = new User();
                $user->setEmail($email);
                $user->setAccountType(User::ACCOUNT_TYPE_COMPANY);
                $user->setCompany($company);
                $user->setPassword($passwordHasher->hashPassword($user, (string) $data['plainPassword']));

                try {
                    $entityManager->persist($company);
                    $entityManager->persist($user);
                    $entityManager->flush();

                    $this->addFlash('success', 'Compte entreprise cree. Veuillez vous connecter.');
                    return $this->redirectToRoute('app_login');
                } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                    $form->addError(new FormError('Des donnees existent deja. Verifiez le nom de l\'entreprise et l\'email.'));
                }
            }
        }

        return $this->render('registration/company.html.twig', [
            'form' => $form,
        ]);
    }

    private static function nullableTrim(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return '' === $trimmed ? null : $trimmed;
    }

    #[Route('/register/person', name: 'app_register_person')]
    public function registerPerson(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }

        $form = $this->createForm(RegistrationPersonType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstName($data['firstName']);
            $user->setLastName($data['lastName']);
            $user->setAccountType(User::ACCOUNT_TYPE_PERSON);
            $user->setPassword($passwordHasher->hashPassword($user, (string) $data['plainPassword']));

            try {
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Compte candidat cree. Veuillez vous connecter.');
                return $this->redirectToRoute('app_login');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                $form->addError(new FormError('Cet email est deja utilise.'));
                $this->addFlash('error', 'Cet email est deja utilise.');
            }
        }

        return $this->render('registration/person.html.twig', [
            'form' => $form,
        ]);
    }
}
