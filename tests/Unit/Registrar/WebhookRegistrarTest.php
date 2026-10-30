<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Registrar;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrar;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

final class WebhookRegistrarTest extends TestCase
{
    use ProphecyTrait;

    private const KEY = 'a-webhooks-key-of-at-least-32-bytes!';

    /**
     * @test
     */
    public function it_returns_version(): void
    {
        $client = $this->prophesize(ClientInterface::class);
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://example.com/webhook');

        $registrar = new WebhookRegistrar($client->reveal(), $urlGenerator->reveal(), 'key', 'prefix');

        self::assertSame('49fb4dab33cd8cf27e6a9d1944dcbbdb', $registrar->getVersion());
    }

    /**
     * @test
     */
    public function it_replaces_the_previously_registered_webhooks_with_the_current_ones(): void
    {
        $httpClient = $this->prophesize(HttpClientInterface::class);

        $httpClient->sendRequest(ShipmondoApi::request('GET', 'webhooks'))->willReturn(ShipmondoApi::json([
            self::webhook(10, 'Shop - [orders:create]'),
            // registered by someone else, so it must be left alone
            self::webhook(20, 'Another integration - [orders:create]'),
            self::webhook(30, 'Shop - [shipments:cancel]'),
        ]))->shouldBeCalledOnce();

        $httpClient->sendRequest(ShipmondoApi::request('DELETE', 'webhooks/10'))->willReturn(ShipmondoApi::empty())->shouldBeCalledOnce();
        $httpClient->sendRequest(ShipmondoApi::request('DELETE', 'webhooks/30'))->willReturn(ShipmondoApi::empty())->shouldBeCalledOnce();
        $httpClient->sendRequest(ShipmondoApi::request('DELETE', 'webhooks/20'))->shouldNotBeCalled();

        /** @var list<array<string, mixed>> $created */
        $created = [];
        $httpClient->sendRequest(ShipmondoApi::request('POST', 'webhooks'))->will(static function (array $args) use (&$created) {
            $request = $args[0];
            self::assertInstanceOf(RequestInterface::class, $request);

            /** @var array<string, mixed> $body */
            $body = json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);
            $created[] = $body;

            return ShipmondoApi::json(self::webhook(100 + count($created), 'Shop - [created]'));
        });

        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://example.com/webhook/shipmondo');

        $registrar = new WebhookRegistrar(ShipmondoApi::client($httpClient->reveal()), $urlGenerator->reveal(), self::KEY, 'Shop');
        $registrar->register();

        $expected = [];
        foreach ($registrar->getWebhooks() as $webhook) {
            $expected[] = [
                'name' => $webhook->name,
                'endpoint' => 'https://example.com/webhook/shipmondo',
                'key' => self::KEY,
                'action' => $webhook->action->value,
                'resource_name' => $webhook->resource->value,
            ];
        }

        self::assertCount(11, $expected);
        self::assertSame($expected, $created);
        self::assertSame('Shop - [orders:create]', $created[2]['name']);
        self::assertSame('Shop - [shipment_monitor:delivered]', $created[10]['name']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function webhook(int $id, string $name): array
    {
        return [
            'id' => $id,
            'endpoint' => 'https://example.com/webhook/shipmondo',
            'active' => true,
            'name' => $name,
            'action' => 'create',
            'resource_name' => 'Orders',
        ];
    }
}
