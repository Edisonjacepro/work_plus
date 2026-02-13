<?php

namespace App\Tests\Service;

use App\Entity\Application;
use App\Entity\ApplicationMessage;
use App\Entity\Offer;
use App\Entity\User;
use App\Service\ApplicationMessageNotifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ApplicationMessageNotifierTest extends TestCase
{
    public function testRecruiterMessageNotifiesCandidate(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $router = $this->createMock(UrlGeneratorInterface::class);

        $router->expects(self::once())
            ->method('generate')
            ->willReturn('http://localhost/applications/5');

        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                $to = $email->getTo();
                return 1 === count($to) && 'candidate@example.com' === $to[0]->getAddress();
            }));

        $notifier = new ApplicationMessageNotifier($mailer, $router, 'no-reply@workplus.local', sys_get_temp_dir());

        $application = $this->buildApplication();

        $message = new ApplicationMessage();
        $message->setApplication($application);
        $message->setAuthorType(ApplicationMessage::AUTHOR_TYPE_RECRUITER);
        $message->setBody('Bonjour');

        $notifier->sendNewMessageNotification($message);
    }

    public function testCandidateMessageNotifiesRecruiter(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $router = $this->createMock(UrlGeneratorInterface::class);

        $router->expects(self::once())
            ->method('generate')
            ->willReturn('http://localhost/applications/5');

        $mailer->expects(self::once())
            ->method('send')
            ->with(self::callback(function (Email $email): bool {
                $to = $email->getTo();
                return 1 === count($to) && 'recruiter@example.com' === $to[0]->getAddress();
            }));

        $notifier = new ApplicationMessageNotifier($mailer, $router, 'no-reply@workplus.local', sys_get_temp_dir());

        $application = $this->buildApplication();

        $message = new ApplicationMessage();
        $message->setApplication($application);
        $message->setAuthorType(ApplicationMessage::AUTHOR_TYPE_CANDIDATE);
        $message->setBody('Je suis disponible.');

        $notifier->sendNewMessageNotification($message);
    }

    private function buildApplication(): Application
    {
        $recruiter = new User();
        $recruiter->setEmail('recruiter@example.com');

        $offer = new Offer();
        $offer->setTitle('Offre test');
        $offer->setAuthor($recruiter);

        $application = new Application();
        $application->setOffer($offer);
        $application->setEmail('candidate@example.com');

        $reflection = new \ReflectionProperty(Application::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($application, 5);

        return $application;
    }
}
