<?php

namespace App\Controller;

use App\Entity\Application;
use App\Entity\ApplicationAttachment;
use App\Entity\ApplicationMessage;
use App\Entity\User;
use App\Form\ApplicationMessageType;
use App\Repository\ApplicationRepository;
use App\Security\ApplicationVoter;
use App\Service\ApplicationMessageNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/applications')]
#[IsGranted('ROLE_USER')]
class ApplicationController extends AbstractController
{
    #[Route('/recruiter', name: 'application_recruiter_index', methods: ['GET'])]
    public function recruiterIndex(ApplicationRepository $applicationRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isCompany()) {
            $this->addFlash('error', 'Acces refuse : vous devez etre connecte en tant que recruteur.');
            return $this->redirectToRoute('home');
        }

        $applications = $this->isGranted('ROLE_ADMIN')
            ? $applicationRepository->findBy([], ['createdAt' => 'DESC'])
            : $applicationRepository->findForRecruiter($user);

        return $this->render('application/recruiter_index.html.twig', [
            'applications' => $applications,
        ]);
    }

    #[Route('/me', name: 'application_candidate_index', methods: ['GET'])]
    public function candidateIndex(ApplicationRepository $applicationRepository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User || !$user->isPerson()) {
            $this->addFlash('error', 'Acces refuse : vous devez etre connecte en tant que candidat.');
            return $this->redirectToRoute('home');
        }

        return $this->render('application/candidate_index.html.twig', [
            'applications' => $applicationRepository->findForCandidate($user),
        ]);
    }

    #[Route('/{id}', name: 'application_show', methods: ['GET'])]
    public function show(Application $application): Response
    {
        if (!$this->isGranted(ApplicationVoter::VIEW, $application)) {
            throw $this->createAccessDeniedException();
        }

        $message = new ApplicationMessage();
        $message->setApplication($application);

        $form = $this->createForm(ApplicationMessageType::class, $message, [
            'action' => $this->generateUrl('application_reply', ['id' => $application->getId()]),
            'method' => 'POST',
        ]);

        return $this->render('application/show.html.twig', [
            'application' => $application,
            'messageForm' => $form->createView(),
        ]);
    }

    #[Route('/{id}/reply', name: 'application_reply', methods: ['POST'])]
    public function reply(
        Request $request,
        Application $application,
        EntityManagerInterface $entityManager,
        ApplicationMessageNotifier $notifier,
        string $applicationAttachmentDir,
    ): Response
    {
        if (!$this->isGranted(ApplicationVoter::REPLY, $application)) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $message = new ApplicationMessage();
        $message->setApplication($application);
        $message->setAuthor($user);
        $message->setAuthorType($user->isCompany() ? ApplicationMessage::AUTHOR_TYPE_RECRUITER : ApplicationMessage::AUTHOR_TYPE_CANDIDATE);

        $form = $this->createForm(ApplicationMessageType::class, $message);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('error', 'Le formulaire de reponse est invalide.');

            return $this->render('application/show.html.twig', [
                'application' => $application,
                'messageForm' => $form->createView(),
            ]);
        }

        /** @var list<UploadedFile> $files */
        $files = $form->get('attachments')->getData() ?? [];
        if (!is_dir($applicationAttachmentDir)) {
            @mkdir($applicationAttachmentDir, 0775, true);
        }

        foreach ($files as $file) {
            if (!$file instanceof UploadedFile) {
                continue;
            }

            $originalName = (string) $file->getClientOriginalName();
            $mimeType = $file->getClientMimeType();
            $size = $file->getSize() ?? 0;

            $storedName = bin2hex(random_bytes(16));
            $extension = $file->guessExtension() ?: $file->getClientOriginalExtension();
            if ($extension) {
                $storedName .= '.' . strtolower($extension);
            }

            try {
                $file->move($applicationAttachmentDir, $storedName);
            } catch (FileException) {
                $this->addFlash('error', 'Impossible de televerser une piece jointe.');
                return $this->redirectToRoute('application_show', ['id' => $application->getId()]);
            }

            if ($size <= 0) {
                $storedPath = rtrim($applicationAttachmentDir, '/\\') . DIRECTORY_SEPARATOR . $storedName;
                $size = is_file($storedPath) ? (filesize($storedPath) ?: 0) : 0;
            }

            $attachment = new ApplicationAttachment();
            $attachment->setMessage($message);
            $attachment->setStoredName($storedName);
            $attachment->setOriginalName($originalName);
            $attachment->setMimeType($mimeType);
            $attachment->setSize((int) $size);
            $message->addAttachment($attachment);
        }

        $entityManager->persist($message);
        $entityManager->flush();

        try {
            $notifier->sendNewMessageNotification($message);
        } catch (\Throwable) {
            $this->addFlash('error', 'Le message est enregistre, mais la notification email a echoue.');
        }

        $this->addFlash('success', 'Votre message a ete envoye.');

        return $this->redirectToRoute('application_show', ['id' => $application->getId()]);
    }

    #[Route('/attachments/{id}/download', name: 'application_attachment_download', methods: ['GET'])]
    public function downloadAttachment(ApplicationAttachment $attachment, string $applicationAttachmentDir): Response
    {
        $application = $attachment->getMessage()?->getApplication();

        if (!$application instanceof Application || !$this->isGranted(ApplicationVoter::VIEW, $application)) {
            throw $this->createAccessDeniedException();
        }

        $fullPath = rtrim($applicationAttachmentDir, '/\\') . DIRECTORY_SEPARATOR . $attachment->getStoredName();
        if (!is_file($fullPath)) {
            throw $this->createNotFoundException('Fichier introuvable.');
        }

        $response = new BinaryFileResponse($fullPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $attachment->getOriginalName() ?? 'piece-jointe');

        return $response;
    }
}
