<?php

namespace App\Tests\Security;

use App\Entity\Company;
use App\Entity\User;
use App\Security\CompanyVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class CompanyVoterTest extends TestCase
{
    private CompanyVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new CompanyVoter();
    }

    public function testAdminCanEditAnyCompany(): void
    {
        $admin = (new User())->setRoles(['ROLE_ADMIN']);
        $company = new Company();

        $result = $this->voter->vote($this->tokenFor($admin), $company, [CompanyVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testRecruiterCanEditOwnCompanyWithSameId(): void
    {
        $user = (new User())->setAccountType(User::ACCOUNT_TYPE_COMPANY);
        $userCompany = (new Company())->setName('Work Plus');
        $targetCompany = (new Company())->setName('Work Plus');
        $this->setEntityId($userCompany, 42);
        $this->setEntityId($targetCompany, 42);
        $user->setCompany($userCompany);

        $result = $this->voter->vote($this->tokenFor($user), $targetCompany, [CompanyVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testRecruiterCannotEditOtherCompany(): void
    {
        $user = (new User())->setAccountType(User::ACCOUNT_TYPE_COMPANY);
        $userCompany = (new Company())->setName('Company A');
        $otherCompany = (new Company())->setName('Company B');
        $this->setEntityId($userCompany, 1);
        $this->setEntityId($otherCompany, 2);
        $user->setCompany($userCompany);

        $result = $this->voter->vote($this->tokenFor($user), $otherCompany, [CompanyVoter::EDIT]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testPersonAccountCannotDeleteCompany(): void
    {
        $person = (new User())->setAccountType(User::ACCOUNT_TYPE_PERSON);
        $company = (new Company())->setName('Work Plus');
        $this->setEntityId($company, 5);

        $result = $this->voter->vote($this->tokenFor($person), $company, [CompanyVoter::DELETE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousCannotViewCompanyViaVoter(): void
    {
        $company = (new Company())->setName('Work Plus');

        $result = $this->voter->vote(new NullToken(), $company, [CompanyVoter::VIEW]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    private function tokenFor(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function setEntityId(Company $company, int $id): void
    {
        $reflection = new \ReflectionProperty(Company::class, 'id');
        $reflection->setAccessible(true);
        $reflection->setValue($company, $id);
    }
}
