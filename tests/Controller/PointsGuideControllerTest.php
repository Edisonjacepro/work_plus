<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class PointsGuideControllerTest extends WebTestCase
{
    public function testPointsGuidePageIsAccessibleAndContainsKeyRules(): void
    {
        $client = static::createClient();
        $client->request('GET', '/points');

        self::assertResponseIsSuccessful();

        $content = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Comprendre le systeme de points Work+', $content);
        self::assertStringContainsString('Pour les candidats', $content);
        self::assertStringContainsString('Pour les entreprises', $content);
        self::assertStringContainsString('ledger', $content);
    }
}
