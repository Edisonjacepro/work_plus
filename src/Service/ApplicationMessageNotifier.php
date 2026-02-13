<?php

namespace App\Service;

use App\Entity\Application;
use App\Entity\ApplicationMessage;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ApplicationMessageNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailerFrom,
        private readonly string $applicationAttachmentDir,
    ) {
    }

    public function sendNewMessageNotification(ApplicationMessage $message): void
    {
        $application = $message->getApplication();
        if (!$application instanceof Application) {
            return;
        }

        $recipient = $this->resolveRecipient($application, $message);
        if (null === $recipient) {
            return;
        }

        $applicationUrl = $this->urlGenerator->generate('application_show', [
            'id' => $application->getId(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Work+'))
            ->to($recipient)
            ->subject('Nouveau message sur une candidature')
            ->text(sprintf(
                "Un nouveau message a ete envoye sur la candidature #%d pour l'offre '%s'.\n\nMessage:\n%s\n\nConsulter la candidature: %s",
                $application->getId(),
                $application->getOffer()?->getTitle() ?? '-',
                $message->getBody(),
                $applicationUrl,
            ));

        foreach ($message->getAttachments() as $attachment) {
            $fullPath = rtrim($this->applicationAttachmentDir, '/\\') . DIRECTORY_SEPARATOR . $attachment->getStoredName();
            if (is_file($fullPath)) {
                $email->attachFromPath($fullPath, $attachment->getOriginalName() ?? 'piece-jointe');
            }
        }

        $this->mailer->send($email);
    }

    private function resolveRecipient(Application $application, ApplicationMessage $message): ?string
    {
        if (ApplicationMessage::AUTHOR_TYPE_RECRUITER === $message->getAuthorType()) {
            $email = $application->getEmail();
            return is_string($email) && '' !== trim($email) ? $email : null;
        }

        $recruiterEmail = $application->getOffer()?->getAuthor()?->getEmail();

        return is_string($recruiterEmail) && '' !== trim($recruiterEmail) ? $recruiterEmail : null;
    }
}
