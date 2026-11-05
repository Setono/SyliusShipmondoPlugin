<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Registrar;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Registrar\Webhook;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrar;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\OrderWebhookHandlerSpy;

final class WebhookRegistrarTest extends TestCase
{
    use ProphecyTrait;

    private const KEY = 'a-webhooks-key-of-at-least-32-bytes!';

    /**
     * @test
     */
    public function it_registers_each_action_a_webhook_handler_handles(): void
    {
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://example.com/webhook');

        $registrar = new WebhookRegistrar(ShipmondoApi::client(new ScriptedHttpClient()), $urlGenerator->reveal(), self::handlers(), 'key', 'prefix');

        self::assertEquals([
            new Webhook('prefix - [orders:status_update]', 'https://example.com/webhook', 'key', WebhookAction::StatusUpdate, WebhookResourceName::Orders),
            new Webhook('prefix - [orders:create_shipment]', 'https://example.com/webhook', 'key', WebhookAction::CreateShipment, WebhookResourceName::Orders),
            new Webhook('prefix - [orders:delete]', 'https://example.com/webhook', 'key', WebhookAction::Delete, WebhookResourceName::Orders),
        ], iterator_to_array($registrar->getWebhooks(), false));
        self::assertSame('00dc066b504bf3f9ef2067a52b04c921', $registrar->getVersion());
    }

    /**
     * @test
     */
    public function it_registers_no_webhooks_without_webhook_handlers(): void
    {
        $registrar = new WebhookRegistrar(ShipmondoApi::client(new ScriptedHttpClient()), $this->prophesize(UrlGeneratorInterface::class)->reveal(), new OrderWebhookHandlers([]), 'key', 'prefix');

        self::assertSame([], iterator_to_array($registrar->getWebhooks()));
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

        $registrar = new WebhookRegistrar(ShipmondoApi::client($httpClient->reveal()), $urlGenerator->reveal(), self::handlers(), self::KEY, 'Shop');
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

        self::assertCount(3, $expected);
        self::assertSame($expected, $created);
    }

    private static function handlers(): OrderWebhookHandlers
    {
        $log = new \ArrayObject();

        return new OrderWebhookHandlers([
            'delete' => [new OrderWebhookHandlerSpy('deleted', $log)],
            'create_shipment' => [new OrderWebhookHandlerSpy('shipped', $log)],
            'status_update' => [new OrderWebhookHandlerSpy('shipped', $log)],
        ]);
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
