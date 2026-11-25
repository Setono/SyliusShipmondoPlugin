<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Registrar;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\Shipmondo\Exception\ValidationException;
use Setono\Shipmondo\Response\Webhook\Webhook as WebhookResponse;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooksInterface;
use Setono\SyliusShipmondoPlugin\Registrar\UnreachableWebhookEndpointException;
use Setono\SyliusShipmondoPlugin\Registrar\Webhook;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrar;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Sylius\Component\Resource\Factory\Factory;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\OrderWebhookHandlerSpy;

final class WebhookRegistrarTest extends TestCase
{
    use ProphecyTrait;

    private const KEY = 'a-webhooks-key-of-at-least-32-bytes!';

    private const ENDPOINT = 'https://shop.example.com/webhook/shipmondo';

    /** @var ObjectProphecy<RegisteredWebhooksRepositoryInterface> */
    private ObjectProphecy $registeredWebhooksRepository;

    private FactoryInterface $registeredWebhooksFactory;

    private RecordingLogger $logger;

    /** @var list<RegisteredWebhooksInterface> the registrations recorded, each as it was when it was recorded */
    private array $recorded = [];

    protected function setUp(): void
    {
        $this->registeredWebhooksRepository = $this->prophesize(RegisteredWebhooksRepositoryInterface::class);
        $this->registeredWebhooksRepository->findLatest()->willReturn(null);
        $recorded = &$this->recorded;
        // Prophecy binds a callback to the prophecy, so it reaches the list by reference
        $this->registeredWebhooksRepository->add(Argument::type(RegisteredWebhooksInterface::class))->will(static function (array $args) use (&$recorded): void {
            self::assertInstanceOf(RegisteredWebhooksInterface::class, $args[0]);
            $recorded[] = clone $args[0];
        });
        $this->registeredWebhooksFactory = new Factory(RegisteredWebhooks::class);
        $this->logger = new RecordingLogger();
    }

    /**
     * @test
     */
    public function it_only_fetches_the_webhooks_when_shipmondo_has_exactly_the_wanted_ones_and_records_the_registration(): void
    {
        $shipmondo = (new ScriptedHttpClient())->respond(200, self::wanted(self::ENDPOINT));

        $before = new \DateTimeImmutable();
        $this->registrar($shipmondo)->register();

        self::assertSame(['GET webhooks'], $shipmondo->requestLines());
        self::assertCount(1, $this->recorded);
        self::assertSame(self::ENDPOINT, $this->recorded[0]->getEndpoint());
        self::assertGreaterThanOrEqual($before, $this->recorded[0]->getRegisteredAt());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $this->recorded[0]->getRegisteredAt());
        // nothing changed, so nothing is worth logging
        self::assertSame([], $this->logger->records);
    }

    /**
     * E.g. after an upgrade of the plugin that handles a new action and no longer handles another
     *
     * @test
     */
    public function it_creates_only_the_webhook_for_a_newly_handled_action_and_deletes_only_the_one_for_an_action_no_longer_handled(): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [
                self::webhook(1, 'Shop [ab9f9937] - [orders:status_update]', self::ENDPOINT, 'status_update'),
                self::webhook(2, 'Shop [ab9f9937] - [orders:create_shipment]', self::ENDPOINT, 'create_shipment'),
                // no handler handles it any longer
                self::webhook(4, 'Shop [ab9f9937] - [orders:payment_captured]', self::ENDPOINT, 'payment_captured'),
            ])
            ->respond(201, self::webhook(5, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete'))
            ->respond(204)
        ;

        $this->registrar($shipmondo)->register();

        self::assertSame(['GET webhooks', 'POST webhooks', 'DELETE webhooks/4'], $shipmondo->requestLines());
        self::assertSame([[
            'name' => 'Shop [ab9f9937] - [orders:delete]',
            'endpoint' => self::ENDPOINT,
            'key' => self::KEY,
            'action' => 'delete',
            'resource_name' => 'Orders',
        ]], self::created($shipmondo));
        self::assertCount(1, $this->recorded);
        self::assertSame([[
            'level' => 'info',
            'message' => 'This shop\'s webhooks in Shipmondo did not match the wanted ones at {endpoint}, so the missing ones were created and the unwanted ones deleted',
            'context' => [
                'endpoint' => self::ENDPOINT,
                'created' => ['Shop [ab9f9937] - [orders:delete]'],
                'deleted' => ['Shop [ab9f9937] - [orders:payment_captured] (4)'],
            ],
        ]], $this->logger->records);
    }

    /**
     * Shipmondo deactivates a webhook, e.g. when its endpoint keeps failing, and sends nothing for it. And a webhook sent
     * to another endpoint, e.g. the shop's address before it changed, doesn't reach the shop
     *
     * @test
     */
    public function it_replaces_an_inactive_webhook_and_one_sent_to_another_endpoint(): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [
                self::webhook(1, 'Shop [ab9f9937] - [orders:status_update]', self::ENDPOINT, 'status_update', active: false),
                self::webhook(2, 'Shop [ab9f9937] - [orders:create_shipment]', 'https://old-address.example.com/webhook/shipmondo', 'create_shipment'),
                self::webhook(3, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete'),
            ])
            ->respond(201, self::webhook(4, 'Shop [ab9f9937] - [orders:status_update]', self::ENDPOINT, 'status_update'))
            ->respond(201, self::webhook(5, 'Shop [ab9f9937] - [orders:create_shipment]', self::ENDPOINT, 'create_shipment'))
            ->respond(204)
            ->respond(204)
        ;

        $this->registrar($shipmondo)->register();

        // the new ones are created first, so a webhook Shipmondo refuses to create leaves the old one in place
        self::assertSame(['GET webhooks', 'POST webhooks', 'POST webhooks', 'DELETE webhooks/1', 'DELETE webhooks/2'], $shipmondo->requestLines());
        self::assertSame(
            [['Shop [ab9f9937] - [orders:status_update]', self::ENDPOINT], ['Shop [ab9f9937] - [orders:create_shipment]', self::ENDPOINT]],
            array_map(static fn (array $body): array => [$body['name'], $body['endpoint']], self::created($shipmondo)),
        );
    }

    /**
     * A webhook that differs from the wanted one in anything Shipmondo lists, e.g. one an admin edited in Shipmondo, is
     * replaced
     *
     * @test
     *
     * @dataProvider webhooksThatDifferFromTheWantedOne
     *
     * @param array<string, mixed> $webhook
     */
    public function it_replaces_a_webhook_that_differs_from_the_wanted_one(array $webhook): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [$webhook])
            ->respond(201, self::webhook(4, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete'))
            ->respond(204)
        ;

        $this->registrar($shipmondo, self::handlersOf('delete'))->register();

        self::assertSame(['GET webhooks', 'POST webhooks', 'DELETE webhooks/1'], $shipmondo->requestLines());
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function webhooksThatDifferFromTheWantedOne(): iterable
    {
        yield 'inactive' => [self::webhook(1, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete', active: false)];
        yield 'another name' => [self::webhook(1, 'Shop [ab9f9937] - [orders:delete] (edited)', self::ENDPOINT, 'delete')];
        yield 'another endpoint' => [self::webhook(1, 'Shop [ab9f9937] - [orders:delete]', 'https://shop.example.com/another-webhook', 'delete')];
        yield 'another action' => [self::webhook(1, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'create')];
        // the SDK reads a listed webhook's resource and action each on its own, so only the resource differs
        yield 'another resource' => [self::webhook(1, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete', resource: 'Shipment Monitor')];
    }

    /**
     * Two upload runs at once may both create a missing webhook, which would send the shop every such webhook twice
     *
     * @test
     */
    public function it_deletes_a_duplicate_of_a_wanted_webhook(): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [...self::wanted(self::ENDPOINT), self::webhook(4, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete')])
            ->respond(204)
        ;

        $this->registrar($shipmondo)->register();

        self::assertSame(['GET webhooks', 'DELETE webhooks/4'], $shipmondo->requestLines());
        // nothing was missing, but something was deleted
        self::assertSame([[], ['Shop [ab9f9937] - [orders:delete] (4)']], [$this->logger->records[0]['context']['created'], $this->logger->records[0]['context']['deleted']]);
    }

    /**
     * @test
     */
    public function it_leaves_another_shops_and_another_integrations_webhooks_alone(): void
    {
        $anotherShopsEndpoint = 'https://another-shop.example.com/webhook/shipmondo';

        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [
                // another integration's, listed before this shop's
                self::webhook(10, 'Another integration - [orders:create]', 'https://another-integration.example.com/hook', 'create'),
                ...self::wanted(self::ENDPOINT),
                // this shop's, registered before webhooks carried the shop's id
                self::webhook(11, 'Shop - [orders:delete]', self::ENDPOINT, 'delete'),
                // this shop's, registered with an earlier webhooks key
                self::webhook(12, 'Shop [0badc0de] - [orders:delete]', self::ENDPOINT, 'delete'),
                // another shop's (c10d3f55 is its id), with and without its id
                self::webhook(13, 'Shop [c10d3f55] - [orders:delete]', $anotherShopsEndpoint, 'delete'),
                self::webhook(14, 'Shop - [orders:delete]', $anotherShopsEndpoint, 'delete'),
                // another integration's, at this shop's endpoint
                self::webhook(15, 'Another integration - [orders:delete]', self::ENDPOINT, 'delete'),
            ])
            ->respond(204)
            ->respond(204)
        ;

        $this->registrar($shipmondo)->register();

        self::assertSame(['GET webhooks', 'DELETE webhooks/11', 'DELETE webhooks/12'], $shipmondo->requestLines());
    }

    /**
     * The SDK reads an action or a resource it doesn't know as null, e.g. one Shipmondo added later, so the list can
     * still be compared
     *
     * @test
     */
    public function it_leaves_alone_another_integrations_webhook_whose_action_or_resource_the_sdk_does_not_know(): void
    {
        $shipmondo = (new ScriptedHttpClient())->respond(200, [
            self::webhook(10, 'Another integration', 'https://another-integration.example.com/hook', 'an_action_added_later', resource: 'A resource added later'),
            ...self::wanted(self::ENDPOINT),
        ]);

        $this->registrar($shipmondo)->register();

        self::assertSame(['GET webhooks'], $shipmondo->requestLines());
    }

    /**
     * E.g. one edited in Shipmondo to an action the SDK doesn't know: it can't be a wanted one
     *
     * @test
     */
    public function it_deletes_a_webhook_of_this_shops_whose_action_the_sdk_does_not_know(): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [...self::wanted(self::ENDPOINT), self::webhook(4, 'Shop [ab9f9937] - [orders:an_action_added_later]', self::ENDPOINT, 'an_action_added_later')])
            ->respond(204)
        ;

        $this->registrar($shipmondo)->register();

        self::assertSame(['GET webhooks', 'DELETE webhooks/4'], $shipmondo->requestLines());
    }

    /**
     * @test
     */
    public function it_reads_every_page_of_webhooks(): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [self::wanted(self::ENDPOINT)[0]], headers: ['X-Total-Pages' => '2'])
            ->respond(200, array_slice(self::wanted(self::ENDPOINT), 1), headers: ['X-Total-Pages' => '2'])
        ;

        $this->registrar($shipmondo)->register();

        self::assertSame(['GET webhooks', 'GET webhooks'], $shipmondo->requestLines());
    }

    /**
     * There is one registration, the latest: each registration records its endpoint and time on it
     *
     * @test
     */
    public function it_records_the_registration_on_the_latest_one(): void
    {
        $latest = new RegisteredWebhooks();
        $latest->setEndpoint('https://old-address.example.com/webhook/shipmondo');
        $latest->setRegisteredAt(new \DateTimeImmutable('2020-01-01'));
        $this->registeredWebhooksRepository->findLatest()->willReturn($latest);

        $this->registrar((new ScriptedHttpClient())->respond(200, self::wanted(self::ENDPOINT)))->register();

        $this->registeredWebhooksRepository->add($latest)->shouldHaveBeenCalledOnce();
        self::assertSame(self::ENDPOINT, $latest->getEndpoint());
        self::assertGreaterThan(new \DateTimeImmutable('2020-01-01'), $latest->getRegisteredAt());
    }

    /**
     * The new webhooks are created first, so a failure part of the way leaves the ones they replace in place
     *
     * @test
     */
    public function it_records_nothing_when_shipmondo_fails_to_create_a_webhook(): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [self::webhook(1, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete', active: false)])
            ->respond(422, ['error' => 'Endpoint did not respond with 200'])
        ;

        try {
            $this->registrar($shipmondo, self::handlersOf('delete'))->register();
            self::fail('The registration did not fail');
        } catch (ValidationException) {
        }

        self::assertSame(['GET webhooks', 'POST webhooks'], $shipmondo->requestLines());
        self::assertSame([], $this->recorded);
    }

    /**
     * @test
     */
    public function it_records_nothing_when_shipmondo_fails_to_delete_a_webhook(): void
    {
        $shipmondo = (new ScriptedHttpClient())
            ->respond(200, [...self::wanted(self::ENDPOINT), self::webhook(4, 'Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, 'delete')])
            ->respond(503)
        ;

        try {
            $this->registrar($shipmondo)->register();
            self::fail('The registration did not fail');
        } catch (ShipmondoException) {
        }

        self::assertSame([], $this->recorded);
        self::assertSame([], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_throws_when_the_factory_does_not_create_registered_webhooks(): void
    {
        $this->registeredWebhooksFactory = new Factory(\stdClass::class);

        $this->expectException(\InvalidArgumentException::class);

        $this->registrar((new ScriptedHttpClient())->respond(200, self::wanted(self::ENDPOINT)))->register();
    }

    /**
     * Registering makes this shop's webhooks match the wanted ones, so registering them where Shipmondo can't send them
     * would also replace the ones that work, e.g. when a developer opens the admin at localhost with the production
     * credentials
     *
     * @test
     *
     * @dataProvider unreachableEndpoints
     */
    public function it_registers_no_webhooks_at_an_endpoint_shipmondo_cannot_reach(string $endpoint): void
    {
        $shipmondo = new ScriptedHttpClient();

        try {
            $this->registrar($shipmondo, endpoint: $endpoint)->register();
            self::fail('The webhooks were registered');
        } catch (UnreachableWebhookEndpointException $e) {
            self::assertSame($endpoint, $e->endpoint);
            self::assertSame(sprintf('The webhooks were not registered: Shipmondo can\'t reach their endpoint, %s.', $endpoint), $e->getMessage());
        }

        // not even this shop's webhooks were fetched, and the registration recorded before still says where they are sent
        self::assertSame([], $shipmondo->requestLines());
        self::assertSame([], $this->recorded);
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
        $shipmondo = (new ScriptedHttpClient())->respond(200, self::wanted($endpoint));

        $this->registrar($shipmondo, endpoint: $endpoint)->register();

        self::assertSame($endpoint, $this->recorded[0]->getEndpoint());
    }

    /**
     * @test
     */
    public function it_compares_this_shops_webhooks_in_shipmondo_with_the_wanted_ones_without_changing_anything(): void
    {
        $shipmondo = (new ScriptedHttpClient())->respond(200, [
            self::webhook(1, 'Shop [ab9f9937] - [orders:status_update]', self::ENDPOINT, 'status_update'),
            self::webhook(4, 'Shop [ab9f9937] - [orders:payment_captured]', self::ENDPOINT, 'payment_captured'),
            self::webhook(5, 'Another integration - [orders:delete]', self::ENDPOINT, 'delete'),
        ]);

        $comparison = $this->registrar($shipmondo)->compare(self::ENDPOINT);

        self::assertFalse($comparison->matches());
        self::assertEquals([
            new Webhook('Shop [ab9f9937] - [orders:create_shipment]', self::ENDPOINT, self::KEY, WebhookAction::CreateShipment, WebhookResourceName::Orders),
            new Webhook('Shop [ab9f9937] - [orders:delete]', self::ENDPOINT, self::KEY, WebhookAction::Delete, WebhookResourceName::Orders),
        ], $comparison->missing);
        self::assertSame([4], array_map(static fn (WebhookResponse $webhook): int => $webhook->id, $comparison->unwanted));
        self::assertSame(['GET webhooks'], $shipmondo->requestLines());
        self::assertSame([], $this->recorded);
    }

    /**
     * @test
     */
    public function it_names_the_webhooks_after_the_shop_whose_webhooks_key_they_carry(): void
    {
        $missing = fn (string $webhooksKey, string $namePrefix): array => array_map(
            static fn (Webhook $webhook): string => $webhook->name,
            $this->registrar((new ScriptedHttpClient())->respond(200, []), webhooksKey: $webhooksKey, namePrefix: $namePrefix)->compare(self::ENDPOINT)->missing,
        );

        self::assertSame([
            'Shop [ab9f9937] - [orders:status_update]',
            'Shop [ab9f9937] - [orders:create_shipment]',
            'Shop [ab9f9937] - [orders:delete]',
        ], $missing(self::KEY, 'Shop'));
        self::assertSame([
            'prefix [c10d3f55] - [orders:status_update]',
            'prefix [c10d3f55] - [orders:create_shipment]',
            'prefix [c10d3f55] - [orders:delete]',
        ], $missing('another-shops-webhooks-key-of-32-bytes!', 'prefix'));
    }

    /**
     * @test
     */
    public function it_wants_no_webhooks_without_webhook_handlers(): void
    {
        $shipmondo = (new ScriptedHttpClient())->respond(200, []);

        self::assertTrue($this->registrar($shipmondo, new OrderWebhookHandlers([]))->compare(self::ENDPOINT)->matches());
    }

    /**
     * @param string $endpoint the endpoint the router generates
     */
    private function registrar(
        HttpClientInterface $shipmondo,
        ?OrderWebhookHandlers $handlers = null,
        string $endpoint = self::ENDPOINT,
        string $webhooksKey = self::KEY,
        string $namePrefix = 'Shop',
    ): WebhookRegistrar {
        return new WebhookRegistrar(
            ShipmondoApi::client($shipmondo),
            $this->urlGenerator($endpoint),
            $handlers ?? self::handlers(),
            $this->registeredWebhooksRepository->reveal(),
            $this->registeredWebhooksFactory,
            $webhooksKey,
            $namePrefix,
            $this->logger,
        );
    }

    /**
     * The bodies of the webhooks Shipmondo was asked to create, in order
     *
     * @return list<array<array-key, mixed>>
     */
    private static function created(ScriptedHttpClient $shipmondo): array
    {
        $created = array_filter($shipmondo->requests(), static fn (RequestInterface $request): bool => 'POST' === $request->getMethod());

        return array_values(array_map(static function (RequestInterface $request): array {
            $body = json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($body);

            return $body;
        }, $created));
    }

    /**
     * The router, which generates the endpoint of the webhooks, e.g. at the host the admin is opened at
     */
    private function urlGenerator(string $endpoint): UrlGeneratorInterface
    {
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL)->willReturn($endpoint);

        return $urlGenerator->reveal();
    }

    /**
     * The plugin's webhook handlers handle these three actions on the orders resource
     */
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
     * Webhook handlers that handle the given action on the orders resource
     */
    private static function handlersOf(string $action): OrderWebhookHandlers
    {
        return new OrderWebhookHandlers([$action => [new OrderWebhookHandlerSpy('handled', new \ArrayObject())]]);
    }

    /**
     * The webhooks Shipmondo lists when it has exactly the wanted ones at the endpoint (ab9f9937 is the shop's id,
     * derived from its webhooks key)
     *
     * @return list<array<string, mixed>>
     */
    private static function wanted(string $endpoint): array
    {
        return [
            self::webhook(1, 'Shop [ab9f9937] - [orders:status_update]', $endpoint, 'status_update'),
            self::webhook(2, 'Shop [ab9f9937] - [orders:create_shipment]', $endpoint, 'create_shipment'),
            self::webhook(3, 'Shop [ab9f9937] - [orders:delete]', $endpoint, 'delete'),
        ];
    }

    /**
     * A webhook as Shipmondo lists it, and answers its creation with
     *
     * @return array<string, mixed>
     */
    private static function webhook(int $id, string $name, string $endpoint, string $action, bool $active = true, string $resource = 'Orders'): array
    {
        return [
            'id' => $id,
            'endpoint' => $endpoint,
            'active' => $active,
            'name' => $name,
            'action' => $action,
            'resource_name' => $resource,
        ];
    }
}
