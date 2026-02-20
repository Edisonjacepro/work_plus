<?php

namespace App\Tests\Security;

use App\Entity\Company;
use App\Entity\PointsClaim;
use App\Entity\User;
use App\Security\PointsClaimVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class PointsClaimVoterTest extends TestCase
{
    private PointsClaimVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PointsClaimVoter();
    }

    public function testAdminCanViewAnyClaim(): void
    {
        $admin = (new User())->setRoles(['ROLE_ADMIN']);
        $claim = (new PointsClaim())->setCompany((new Company())->setName('Impact Co'));

        $result = $this->voter->vote($this->tokenFor($admin), $claim, [PointsClaimVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testCompanyUserCanViewOwnClaim(): void
    {
        $company = (new Company())->setName('Impact Co');
        $this->setEntityId($company, 5);

        $user = (new User())
            ->setAccountType(User::ACCOUNT_TYPE_COMPANY)
            ->setCompany($company);

        $claim = (new PointsClaim())->setCompany((new Company())->setName('Impact Co'));
        $this->setEntityId($claim->getCompany(), 5);

        $result = $this->voter->vote($this->tokenFor($user), $claim, [PointsClaimVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAnonymousCannotViewClaim(): void
    {
        $claim = (new PointsClaim())->setCompany((new Company())->setName('Impact Co'));

        $result = $this->voter->vote(new NullToken(), $claim, [PointsClaimVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function setEntityId(object $entity, int $id): void
    {
        $reflection = new \ReflectionProperty($entity::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($entity, $id);
    }
}
