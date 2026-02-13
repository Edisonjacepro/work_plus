<?php

namespace App\Security;

use App\Entity\Application;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ApplicationVoter extends Voter
{
    public const VIEW = 'APPLICATION_VIEW';
    public const REPLY = 'APPLICATION_REPLY';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::REPLY], true) && $subject instanceof Application;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$subject instanceof Application) {
            return false;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $isCandidate = null !== $subject->getCandidate() && $subject->getCandidate()?->getId() === $user->getId();
        $author = $subject->getOffer()?->getAuthor();
        $isRecruiter = null !== $author && $author->getId() === $user->getId();

        return $isCandidate || $isRecruiter;
    }
}
