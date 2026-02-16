<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PricingControllerTest extends WebTestCase
{
    public function testPricingPageIsAccessibleAndContainsPlans(): void
    {
        $client = static::createClient();
        $client->request('GET', '/pricing');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Tarifs Work+', $content);
        self::assertStringContainsString('Starter', $content);
        self::assertStringContainsString('Growth', $content);
        self::assertStringContainsString('Scale', $content);
    }
}

