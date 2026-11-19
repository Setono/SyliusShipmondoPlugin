<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Registrar;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Registrar\UnreachableWebhookEndpointException;
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

    private const ANOTHER_SHOPS_KEY = 'another-shops-webhooks-key-of-32-bytes!';

    /**
     * @test
     */
    public function it_registers_each_action_a_webhook_handler_handles(): void
    {
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://example.com/webhook');

        $registrar = new WebhookRegistrar(ShipmondoApi::client(new ScriptedHttpClient()), $urlGenerator->reveal(), self::handlers(), 'key', 'prefix');

        // 3fd02dbf is the shop's id, derived from its webhooks key ('key')
        self::assertEquals([
            new Webhook('prefix [3fd02dbf] - [orders:status_update]', 'https://example.com/webhook', 'key', WebhookAction::StatusUpdate, WebhookResourceName::Orders),
            new Webhook('prefix [3fd02dbf] - [orders:create_shipment]', 'https://example.com/webhook', 'key', WebhookAction::CreateShipment, WebhookResourceName::Orders),
            new Webhook('prefix [3fd02dbf] - [orders:delete]', 'https://example.com/webhook', 'key', WebhookAction::Delete, WebhookResourceName::Orders),
        ], iterator_to_array($registrar->getWebhooks(), false));
        self::assertSame('11a5d6cb7e8aedb8b535b0c0c61fc6c3', $registrar->getVersion());
    }

    /**
     * @test
     */
    public function it_names_the_webhooks_after_the_shop_whose_webhooks_key_they_carry(): void
    {
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn('https://example.com/webhook');

        $names = fn (string $webhooksKey): array => array_map(
            static fn (Webhook $webhook): string => $webhook->name,
            iterator_to_array((new WebhookRegistrar(ShipmondoApi::client(new ScriptedHttpClient()), $urlGenerator->reveal(), self::handlers(), $webhooksKey, 'Shop'))->getWebhooks(), false),
        );

        self::assertSame([
            'Shop [ab9f9937] - [orders:status_update]',
            'Shop [ab9f9937] - [orders:create_shipment]',
            'Shop [ab9f9937] - [orders:delete]',
        ], $names(self::KEY));
        self::assertSame([
            'Shop [c10d3f55] - [orders:status_update]',
            'Shop [c10d3f55] - [orders:create_shipment]',
            'Shop [c10d3f55] - [orders:delete]',
        ], $names(self::ANOTHER_SHOPS_KEY));
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

    /**
     * @test
     */
    public function it_replaces_only_this_shops_webhooks_when_another_shop_shares_the_shipmondo_account(): void
    {
        $endpoint = 'https://shop.example.com/webhook/shipmondo';
        $anotherShopsEndpoint = 'https://another-shop.example.com/webhook/shipmondo';

        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [
                // this shop's (ab9f9937 is its id), registered before the shop moved to its current address
                self::webhook(1, 'Shop [ab9f9937] - [orders:delete]', 'https://old-address.example.com/webhook/shipmondo'),
                // this shop's, registered before webhooks carried the shop's id
                self::webhook(2, 'Shop - [orders:delete]', $endpoint),
                // this shop's, registered with an earlier webhooks key
                self::webhook(3, 'Shop [0badc0de] - [orders:delete]', $endpoint),
                // another shop's, with and without its id
                self::webhook(4, 'Shop [c10d3f55] - [orders:delete]', $anotherShopsEndpoint),
                self::webhook(5, 'Shop - [orders:delete]', $anotherShopsEndpoint),
                // another integration's
                self::webhook(6, 'Another integration - [orders:delete]', $endpoint),
            ])
            ->respond(204)
            ->respond(204)
            ->respond(204)
            ->respond(201, self::webhook(101, 'Shop [ab9f9937] - [orders:status_update]', $endpoint))
            ->respond(201, self::webhook(102, 'Shop [ab9f9937] - [orders:create_shipment]', $endpoint))
            ->respond(201, self::webhook(103, 'Shop [ab9f9937] - [orders:delete]', $endpoint))
        ;

        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn($endpoint);

        (new WebhookRegistrar(ShipmondoApi::client($shipmondo), $urlGenerator->reveal(), self::handlers(), self::KEY, 'Shop'))->register();

        self::assertSame([
            'GET webhooks',
            'DELETE webhooks/1',
            'DELETE webhooks/2',
            'DELETE webhooks/3',
            'POST webhooks',
            'POST webhooks',
            'POST webhooks',
        ], $shipmondo->requestLines());
    }

    /**
     * Registering replaces this shop's webhooks, so registering them where Shipmondo can't send them would also remove
     * the ones that work, e.g. when a developer opens the admin at localhost with the production credentials
     *
     * @test
     *
     * @dataProvider unreachableEndpoints
     */
    public function it_registers_no_webhooks_at_an_endpoint_shipmondo_cannot_reach(string $endpoint): void
    {
        $shipmondo = new ScriptedHttpClient();

        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn($endpoint);

        $registrar = new WebhookRegistrar(ShipmondoApi::client($shipmondo), $urlGenerator->reveal(), self::handlers(), self::KEY, 'Shop');

        try {
            $registrar->register();
            self::fail('The webhooks were registered');
        } catch (UnreachableWebhookEndpointException $e) {
            self::assertSame($endpoint, $e->endpoint);
            self::assertSame(sprintf('The webhooks were not registered: Shipmondo can\'t reach their endpoint, %s.', $endpoint), $e->getMessage());
        }

        // not even this shop's webhooks were removed
        self::assertSame([], $shipmondo->requestLines());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableEndpoints(): iterable
    {
        yield 'localhost' => ['http://localhost:8000/webhook/shipmondo'];
        // e.g. the address `symfony serve` serves a local app at
        yield 'a loopback address' => ['https://127.0.0.1:8000/webhook/shipmondo'];
        yield 'an IPv6 loopback address' => ['http://[::1]/webhook/shipmondo'];
        yield 'a name under localhost' => ['https://shop.localhost/webhook/shipmondo'];
        // the router generates a path when its context has no host
        yield 'no host' => ['/webhook/shipmondo'];
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_at_an_endpoint_on_another_port(): void
    {
        $endpoint = 'http://shop.example.com:8080/webhook/shipmondo';
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [])
            ->respond(201, self::webhook(101, 'Shop [ab9f9937] - [orders:status_update]', $endpoint))
            ->respond(201, self::webhook(102, 'Shop [ab9f9937] - [orders:create_shipment]', $endpoint))
            ->respond(201, self::webhook(103, 'Shop [ab9f9937] - [orders:delete]', $endpoint))
        ;

        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn($endpoint);

        (new WebhookRegistrar(ShipmondoApi::client($shipmondo), $urlGenerator->reveal(), self::handlers(), self::KEY, 'Shop'))->register();

        self::assertSame(['GET webhooks', 'POST webhooks', 'POST webhooks', 'POST webhooks'], $shipmondo->requestLines());
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
    private static function webhook(int $id, string $name, string $endpoint = 'https://example.com/webhook/shipmondo'): array
    {
        return [
            'id' => $id,
            'endpoint' => $endpoint,
            'active' => true,
            'name' => $name,
            'action' => 'create',
            'resource_name' => 'Orders',
        ];
    }
}
