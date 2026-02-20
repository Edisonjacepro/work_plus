<?php

namespace App\Tests\Security;

use App\Entity\Offer;
use App\Entity\User;
use App\Security\OfferVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class OfferVoterTest extends TestCase
{
    private OfferVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new OfferVoter();
    }

    public function testAnonymousCanViewPublishedVisibleOffer(): void
    {
        $offer = (new Offer())
            ->setStatus(Offer::STATUS_PUBLISHED)
            ->setModerationStatus(Offer::MODERATION_STATUS_APPROVED)
            ->setIsVisible(true);

        $result = $this->voter->vote(new NullToken(), $offer, [OfferVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAnonymousCannotViewDraftOffer(): void
    {
        $offer = (new Offer())
            ->setStatus(Offer::STATUS_DRAFT)
            ->setIsVisible(true);

        $result = $this->voter->vote(new NullToken(), $offer, [OfferVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousCannotViewPublishedOfferWhenModerationRejected(): void
    {
        $offer = (new Offer())
            ->setStatus(Offer::STATUS_PUBLISHED)
            ->setModerationStatus(Offer::MODERATION_STATUS_REJECTED)
            ->setIsVisible(true);

        $result = $this->voter->vote(new NullToken(), $offer, [OfferVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAuthorCanViewOwnDraftOffer(): void
    {
        $author = new User();
        $offer = (new Offer())
            ->setStatus(Offer::STATUS_DRAFT)
            ->setIsVisible(false)
            ->setAuthor($author);

        $result = $this->voter->vote($this->tokenFor($author), $offer, [OfferVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testNonAuthorCannotEditOffer(): void
    {
        $author = new User();
        $other = new User();
        $offer = (new Offer())->setAuthor($author);

        $result = $this->voter->vote($this->tokenFor($other), $offer, [OfferVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAdminCanEditAnyOffer(): void
    {
        $author = new User();
        $admin = (new User())->setRoles(['ROLE_ADMIN']);
        $offer = (new Offer())->setAuthor($author);

        $result = $this->voter->vote($this->tokenFor($admin), $offer, [OfferVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
