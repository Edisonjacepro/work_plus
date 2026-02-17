<?php

namespace App\Service;

use App\Entity\PointsClaim;
use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class PointsClaimDecisionNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly string $mailerFrom,
    ) {
    }

    public function sendDecisionNotification(PointsClaim $claim): void
    {
        $recipient = $this->resolveRecipient($claim);
        if (null === $recipient) {
            return;
        }

        $subject = sprintf('Decision sur votre preuve d\'impact #%d', (int) $claim->getId());
        $body = sprintf(
            "Votre preuve d'impact #%d a ete traitee.\n\nStatut: %s\nCode motif: %s\nMotif detaille: %s\nPoints proposes: %d\nPoints approuves: %s\n\nMerci,\nWork+",
            (int) $claim->getId(),
            $claim->getStatus(),
            $claim->getDecisionReasonCode() ?? '-',
            $claim->getDecisionReason() ?? '-',
            $claim->getRequestedPoints(),
            null !== $claim->getApprovedPoints() ? (string) $claim->getApprovedPoints() : '-',
        );

        $email = (new Email())
            ->from(new Address($this->mailerFrom, 'Work+'))
            ->to($recipient)
            ->subject($subject)
            ->text($body);

        $this->mailer->send($email);
    }

    private function resolveRecipient(PointsClaim $claim): ?string
    {
        $company = $claim->getCompany();
        if (null === $company) {
            return null;
        }

        foreach ($company->getUsers() as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $email = $user->getEmail();
            if (is_string($email) && '' !== trim($email)) {
                return $email;
            }
        }

        return null;
    }
}
