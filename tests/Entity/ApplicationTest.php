<?php

namespace App\Tests\Entity;

use App\Entity\Application;
use App\Entity\Offer;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    public function testCanAssignOfferAndCandidate(): void
    {
        $application = new Application();
        $offer = new Offer();
        $candidate = new User();

        $application->setOffer($offer);
        $application->setCandidate($candidate);

        self::assertSame($offer, $application->getOffer());
        self::assertSame($candidate, $application->getCandidate());
    }

    public function testCanStoreIdentityAndMessage(): void
    {
        $application = new Application();

        $application
            ->setEmail('candidate@example.com')
            ->setFirstName('Ada')
            ->setLastName('Lovelace')
            ->setMessage('Je suis interesse par cette offre.')
            ->setCvFilePath('uploads/cv/cv-ada-lovelace.pdf');

        self::assertSame('candidate@example.com', $application->getEmail());
        self::assertSame('Ada', $application->getFirstName());
        self::assertSame('Lovelace', $application->getLastName());
        self::assertSame('Je suis interesse par cette offre.', $application->getMessage());
        self::assertSame('uploads/cv/cv-ada-lovelace.pdf', $application->getCvFilePath());
    }
}
