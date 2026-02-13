<?php

namespace App\Security;

use App\Entity\Offer;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class OfferVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';
    public const PUBLISH = 'PUBLISH';
    public const UNPUBLISH = 'UNPUBLISH';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [
            self::VIEW,
            self::EDIT,
            self::DELETE,
            self::PUBLISH,
            self::UNPUBLISH,
        ], true) && $subject instanceof Offer;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$subject instanceof Offer) {
            return false;
        }

        $user = $token->getUser();

        if ($user instanceof User && in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        if (self::VIEW === $attribute) {
            if ($subject->isVisible() && Offer::STATUS_PUBLISHED === $subject->getStatus()) {
                return true;
            }

            return $user instanceof User && $this->isAuthor($subject, $user);
        }

        return $user instanceof User && $this->isAuthor($subject, $user);
    }

    private function isAuthor(Offer $offer, User $user): bool
    {
        $author = $offer->getAuthor();
        if (null === $author) {
            return false;
        }

        if ($author === $user) {
            return true;
        }

        return null !== $author->getId() && $author->getId() === $user->getId();
    }
}
