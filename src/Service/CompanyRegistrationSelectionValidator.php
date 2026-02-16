<?php

namespace App\Service;

use App\Entity\Company;

class CompanyRegistrationSelectionValidator
{
    public function validate(?Company $existingCompany, string $companyName): ?string
    {
        $normalizedCompanyName = trim($companyName);

        if (null !== $existingCompany && '' !== $normalizedCompanyName) {
            return 'Veuillez soit sélectionner une entreprise existante, soit saisir un nouveau nom.';
        }

        if (null === $existingCompany && '' === $normalizedCompanyName) {
            return 'Veuillez sélectionner une entreprise existante ou en créer une nouvelle.';
        }

        return null;
    }
}
