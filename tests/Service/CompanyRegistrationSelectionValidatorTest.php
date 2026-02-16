<?php

namespace App\Tests\Service;

use App\Entity\Company;
use App\Service\CompanyRegistrationSelectionValidator;
use PHPUnit\Framework\TestCase;

class CompanyRegistrationSelectionValidatorTest extends TestCase
{
    private CompanyRegistrationSelectionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CompanyRegistrationSelectionValidator();
    }

    public function testReturnsErrorWhenNeitherExistingCompanyNorNameProvided(): void
    {
        $error = $this->validator->validate(null, '');

        self::assertSame('Veuillez sélectionner une entreprise existante ou en créer une nouvelle.', $error);
    }

    public function testReturnsErrorWhenBothExistingCompanyAndNameProvided(): void
    {
        $company = (new Company())->setName('Work Plus');

        $error = $this->validator->validate($company, 'Nouvelle entreprise');

        self::assertSame('Veuillez soit sélectionner une entreprise existante, soit saisir un nouveau nom.', $error);
    }

    public function testReturnsNullWhenExistingCompanyProvidedWithoutName(): void
    {
        $company = (new Company())->setName('Work Plus');

        $error = $this->validator->validate($company, '');

        self::assertNull($error);
    }

    public function testReturnsNullWhenOnlyNameProvided(): void
    {
        $error = $this->validator->validate(null, 'Nouvelle entreprise');

        self::assertNull($error);
    }
}
