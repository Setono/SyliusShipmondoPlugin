<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Registrar;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrar;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class WebhookRegistrarTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_returns_version(): void
    {
        $client = $this->prophesize(ClientInterface::class);
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', Argument::type('array'), UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://example.com/webhook');

        $registrar = new WebhookRegistrar($client->reveal(), $urlGenerator->reveal(), 'key', 'prefix');

        self::assertSame('49fb4dab33cd8cf27e6a9d1944dcbbdb', $registrar->getVersion());
    }
}
