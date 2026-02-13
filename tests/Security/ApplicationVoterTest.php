<?php

namespace App\Tests\Security;

use App\Entity\Application;
use App\Entity\Offer;
use App\Entity\User;
use App\Security\ApplicationVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class ApplicationVoterTest extends TestCase
{
    public function testRecruiterCanViewOwnOfferApplications(): void
    {
        $recruiter = (new User())->setEmail('recruiter@example.com')->setAccountType(User::ACCOUNT_TYPE_COMPANY);
        $this->setId($recruiter, 10);

        $offer = new Offer();
        $offer->setAuthor($recruiter);

        $application = new Application();
        $application->setOffer($offer);

        $token = new UsernamePasswordToken($recruiter, 'main', $recruiter->getRoles());

        $voter = new ApplicationVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $application, [ApplicationVoter::VIEW]));
    }

    public function testCandidateCanViewOwnApplication(): void
    {
        $candidate = (new User())->setEmail('candidate@example.com')->setAccountType(User::ACCOUNT_TYPE_PERSON);
        $this->setId($candidate, 42);

        $recruiter = (new User())->setEmail('recruiter@example.com')->setAccountType(User::ACCOUNT_TYPE_COMPANY);
        $this->setId($recruiter, 10);

        $offer = new Offer();
        $offer->setAuthor($recruiter);

        $application = new Application();
        $application->setOffer($offer);
        $application->setCandidate($candidate);

        $token = new UsernamePasswordToken($candidate, 'main', $candidate->getRoles());

        $voter = new ApplicationVoter();

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $application, [ApplicationVoter::VIEW]));
    }

    public function testOtherUsersAreDenied(): void
    {
        $recruiter = (new User())->setEmail('recruiter@example.com')->setAccountType(User::ACCOUNT_TYPE_COMPANY);
        $this->setId($recruiter, 10);

        $otherUser = (new User())->setEmail('other@example.com')->setAccountType(User::ACCOUNT_TYPE_PERSON);
        $this->setId($otherUser, 99);

        $offer = new Offer();
        $offer->setAuthor($recruiter);

        $application = new Application();
        $application->setOffer($offer);

        $token = new UsernamePasswordToken($otherUser, 'main', $otherUser->getRoles());

        $voter = new ApplicationVoter();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $application, [ApplicationVoter::VIEW]));
    }

    private function setId(User $user, int $id): void
    {
        $reflection = new \ReflectionProperty(User::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($user, $id);
    }
}
