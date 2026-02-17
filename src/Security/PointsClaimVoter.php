<?php

namespace App\Security;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class PointsClaimVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const REVIEW = 'REVIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::REVIEW], true) && $subject instanceof PointsClaim;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        if (!$subject instanceof PointsClaim) {
            return false;
        }

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        if (self::REVIEW === $attribute) {
            return false;
        }

        if (!$user->isCompany()) {
            return false;
        }

        $claimCompany = $subject->getCompany();
        $userCompany = $user->getCompany();
        if (!$claimCompany instanceof Company || !$userCompany instanceof Company) {
            return false;
        }

        if ($claimCompany === $userCompany) {
            return true;
        }

        return null !== $claimCompany->getId() && $claimCompany->getId() === $userCompany->getId();
    }
}
