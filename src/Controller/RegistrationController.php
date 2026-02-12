<?php

namespace App\Controller;

use App\Entity\Company;
use App\Entity\User;
use App\Form\RegistrationCompanyType;
use App\Form\RegistrationPersonType;
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
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('home');
        }

        $form = $this->createForm(RegistrationCompanyType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $company = new Company();
            $company->setName($data['companyName']);
            $company->setDescription($data['companyDescription'] ?? null);

            $user = new User();
            $user->setEmail($data['email']);
            $user->setAccountType(User::ACCOUNT_TYPE_COMPANY);
            $user->setCompany($company);
            $user->setPassword($passwordHasher->hashPassword($user, (string) $data['plainPassword']));

            try {
                $entityManager->persist($company);
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'Compte entreprise créé. Veuillez vous connecter.');
                return $this->redirectToRoute('app_login');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                $form->addError(new FormError('Cet email est déjà utilisé.'));
                $this->addFlash('error', 'Cet email est déjà utilisé.');
            }
        }

        return $this->render('registration/company.html.twig', [
            'form' => $form,
        ]);
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

                $this->addFlash('success', 'Compte candidat créé. Veuillez vous connecter.');
                return $this->redirectToRoute('app_login');
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                $form->addError(new FormError('Cet email est déjà utilisé.'));
                $this->addFlash('error', 'Cet email est déjà utilisé.');
            }
        }

        return $this->render('registration/person.html.twig', [
            'form' => $form,
        ]);
    }
}
