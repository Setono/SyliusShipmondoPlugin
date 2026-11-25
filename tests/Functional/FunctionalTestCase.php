<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Setono\SyliusShipmondoPlugin\Event\SalesOrderMappedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\TestApplication;
use Tests\Setono\SyliusShipmondoPlugin\Support\Webhooks;
use Webmozart\Assert\Assert;

/**
 * Boots the real test application against MySQL (DATABASE_URL), starting from the baseline shop
 * ({@see ShopFixtures}), with the Shipmondo API stubbed at the HTTP boundary: the PSR-18 versions of the plugin's
 * HTTP clients are replaced with {@see ShipmondoApiStub}, so the plugin's Shipmondo client, as the container builds
 * it, sends its requests to the stub.
 *
 * Every test runs inside a transaction that dama/doctrine-test-bundle rolls back afterwards. DAMA needs DBAL
 * savepoints for that, so in these tests the application's nested transactions use savepoints. Tests that depend
 * on real commits, or on the nested-transaction semantics consumers have (DBAL 3 without savepoints), override
 * {@see self::commitsForReal()}.
 *
 * The browser does not reboot the kernel between requests, so the stub stays in place for the whole test.
 * Entities loaded before a request are detached afterwards (the entity manager is cleared between requests),
 * so tests assert on persisted state by reading it back through {@see self::database()}.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    /**
     * Where an admin registered the webhooks, in a test that records a registration
     */
    protected const WEBHOOK_ENDPOINT = 'https://shop.example.com/webhook/shipmondo';

    protected KernelBrowser $browser;

    protected ShipmondoApiStub $shipmondo;

    protected ShopFixtures $fixtures;

    private ?Connection $observer = null;

    /**
     * Return true to run the test outside DAMA's rolled-back transaction: the application gets a plain connection
     * whose transactions really commit and nest without savepoints, as in an application using the plugin with
     * DBAL 3's defaults. Such a test empties the database and restores the baseline afterwards.
     */
    protected static function commitsForReal(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->browser = static::createClient();
        $this->browser->disableReboot();

        // must happen before any service that depends on the plugin's HTTP clients is instantiated
        $this->shipmondo = new ShipmondoApiStub();
        $this->shipmondo->install(static::getContainer());

        if (static::commitsForReal()) {
            // DAMA only takes over connections opened while static connections are kept
            StaticDriver::setKeepStaticConnections(false);
            $this->connection()->close();
            $this->connection()->setNestTransactionsWithSavepoints(false);
        }

        $kernel = static::$kernel;
        Assert::notNull($kernel);
        TestApplication::prepare($kernel, static::getContainer());

        $this->fixtures = (new ShopFixtures(static::getContainer()))->load();
    }

    protected function tearDown(): void
    {
        $unexpectedRequests = $this->shipmondo->unexpectedRequests();

        $this->observer?->close();
        $this->observer = null;

        $restoreFailure = null;
        if (static::commitsForReal()) {
            try {
                TestApplication::restoreBaseline(static::getContainer());
                $this->connection()->close();
            } catch (\Throwable $e) {
                $restoreFailure = $e;
            } finally {
                StaticDriver::setKeepStaticConnections(true);
            }
        }

        // shut the kernel down even when restoring failed, so a failing test cannot break the ones after it
        parent::tearDown();

        // PHPUnit keeps every test object until the run ends, and through these the test's container would stay in memory
        unset($this->browser, $this->shipmondo, $this->fixtures);

        if (null !== $restoreFailure) {
            throw $restoreFailure;
        }

        self::assertSame([], $unexpectedRequests, 'The test made Shipmondo requests it did not stub');
    }

    /**
     * Runs a console command as Symfony's "Testing Commands" documentation does: an Application built from the
     * booted kernel finds the command, and a CommandTester executes it. Exceptions the command throws propagate.
     *
     * @param array<string, mixed> $input
     */
    protected function executeCommand(string $name, array $input = []): CommandTester
    {
        $kernel = static::$kernel;
        Assert::notNull($kernel);

        $tester = new CommandTester((new Application($kernel))->find($name));
        $tester->execute($input);

        return $tester;
    }

    /**
     * Answers the request for Shipmondo's payment gateways with the one the baseline's payment method is mapped to, as
     * the upload of an order paid with it asks for them
     */
    protected function respondWithThePaymentGateway(): void
    {
        $this->shipmondo->respond('GET', 'payment_gateways', 200, [
            ['id' => ShopFixtures::PAYMENT_METHOD_SHIPMONDO_ID, 'name' => 'Bank transfer', 'provider' => 'manual', 'merchant_number' => ''],
        ], ['X-Total-Pages' => '1']);
    }

    /**
     * Records a registration of the webhooks, as registering them does. A registration recorded before the plugin
     * recorded the endpoint has none
     */
    protected function recordWebhookRegistration(?string $endpoint, string $registeredAt = '2026-01-02 03:04:05'): void
    {
        $this->connection()->insert('setono_sylius_shipmondo__registered_webhooks', ['endpoint' => $endpoint, 'registeredAt' => $registeredAt]);
    }

    /**
     * The webhooks the plugin wants at the endpoint, as Shipmondo lists them ({@see Webhooks::wanted()})
     *
     * @return list<array{id: int, endpoint: string, active: bool, name: string, action: string, resource_name: string}>
     */
    protected function wantedWebhooks(string $endpoint): array
    {
        return Webhooks::wanted(static::getContainer(), $endpoint);
    }

    /**
     * The endpoint and time of each recorded registration of the webhooks, read from the database
     *
     * @return list<array{endpoint: string|null, registeredAt: string}>
     */
    protected function webhookRegistrations(): array
    {
        /** @var list<array{endpoint: string|null, registeredAt: string}> $registrations */
        $registrations = $this->database()->fetchAllAssociative('SELECT endpoint, registeredAt FROM setono_sylius_shipmondo__registered_webhooks ORDER BY id');

        return $registrations;
    }

    /**
     * What the application logged at info level and above during the test, read from the test application's "recorded"
     * Monolog handler. The level is PSR-3's, e.g. 'error'
     *
     * @return list<array{level: string, message: string, context: array<array-key, mixed>}>
     */
    protected function logs(): array
    {
        $handler = static::getContainer()->get('monolog.handler.recorded');
        Assert::isInstanceOf($handler, TestHandler::class);

        $logs = [];
        // array access reads a record on Monolog 2 (an array) and 3 (a LogRecord) alike
        foreach ($handler->getRecords() as $record) {
            Assert::string($record['level_name']);
            Assert::string($record['message']);
            Assert::isArray($record['context']);

            $logs[] = ['level' => strtolower($record['level_name']), 'message' => $record['message'], 'context' => $record['context']];
        }

        return $logs;
    }

    protected function eventDispatcher(): EventDispatcherInterface
    {
        $eventDispatcher = static::getContainer()->get('event_dispatcher');
        Assert::isInstanceOf($eventDispatcher, EventDispatcherInterface::class);

        return $eventDispatcher;
    }

    /**
     * Runs $listener the first time the application dispatches the event, e.g. to change data behind the application's back
     */
    protected function onceWhen(string $eventName, callable $listener): void
    {
        $done = false;
        $this->eventDispatcher()->addListener($eventName, static function () use (&$done, $listener): void {
            if ($done) {
                return;
            }
            $done = true;

            $listener();
        });
    }

    /**
     * Makes turning the order into a sales order fail, as a data mapper that throws on inconsistent order data does
     */
    protected function failToMap(OrderInterface $order): void
    {
        $orderId = self::id($order);

        $this->eventDispatcher()->addListener(SalesOrderMappedEvent::class, static function (SalesOrderMappedEvent $event) use ($orderId): void {
            if ($orderId === $event->order->getId()) {
                throw new \RuntimeException('The order has no shipping address');
            }
        });
    }

    /**
     * Cancels the order in Sylius outside an HTTP request, as a console command or a message handler would
     */
    protected function cancel(OrderInterface $order): void
    {
        $stateMachine = static::getContainer()->get('sylius_abstraction.state_machine');
        Assert::isInstanceOf($stateMachine, StateMachineInterface::class);

        $stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();
    }

    /**
     * Cancels the order behind the application's back, as an admin does in another request while the application is
     * busy with the order: the database has the cancelled order, the application's entity manager doesn't
     */
    protected function cancelBehindTheApplicationsBack(OrderInterface $order): void
    {
        $this->connection()->executeStatement(
            'UPDATE sylius_order SET state = ?, version = version + 1 WHERE id = ?',
            [OrderInterface::STATE_CANCELLED, self::id($order)],
        );
    }

    protected function entityManager(): EntityManagerInterface
    {
        $entityManager = static::getContainer()->get('doctrine.orm.entity_manager');
        Assert::isInstanceOf($entityManager, EntityManagerInterface::class);

        return $entityManager;
    }

    protected function connection(): Connection
    {
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        Assert::isInstanceOf($connection, Connection::class);

        return $connection;
    }

    /**
     * The connection assertions read the database through.
     *
     * Normally that is the application's own connection, which sees the test's (uncommitted) transaction. A test
     * that commits for real reads through a second connection instead, which only ever sees committed data, so
     * an assertion cannot be fooled by a transaction the application left open.
     */
    protected function database(): Connection
    {
        if (!static::commitsForReal()) {
            return $this->connection();
        }

        return $this->observer ??= DriverManager::getConnection($this->connection()->getParams());
    }

    /**
     * Reads an order's row straight from the database
     *
     * @return array{state: string, checkout_state: string, payment_state: string, shipping_state: string, shipmondo_state: string, shipmondo_id: int|null}
     */
    protected function orderRow(int $orderId): array
    {
        $row = $this->database()->fetchAssociative(
            'SELECT state, checkout_state, payment_state, shipping_state, shipmondoState AS shipmondo_state, shipmondoId AS shipmondo_id FROM sylius_order WHERE id = ?',
            [$orderId],
        );
        Assert::isArray($row, sprintf('The order %d does not exist', $orderId));

        $string = static function (mixed $value): string {
            Assert::string($value);

            return $value;
        };

        return [
            'state' => $string($row['state']),
            'checkout_state' => $string($row['checkout_state']),
            'payment_state' => $string($row['payment_state']),
            'shipping_state' => $string($row['shipping_state']),
            'shipmondo_state' => $string($row['shipmondo_state']),
            'shipmondo_id' => self::nullableInt($row['shipmondo_id']),
        ];
    }

    /**
     * Database drivers return integer columns as strings; this turns such a value back into an int
     */
    protected static function nullableInt(mixed $value): ?int
    {
        if (null === $value) {
            return null;
        }

        Assert::numeric($value);

        return (int) $value;
    }

    protected function loginAs(object $user, string $firewall): void
    {
        Assert::isInstanceOf($user, UserInterface::class);

        $this->browser->loginUser($user, $firewall);
    }

    protected static function choice(Form $form, string $name): ChoiceFormField
    {
        $field = $form->get($name);
        Assert::isInstanceOf($field, ChoiceFormField::class);

        return $field;
    }

    /**
     * Skips a test that demonstrates an open bug in the plugin, so the suite stays green until it is fixed.
     * Remove the call together with the fix.
     */
    protected static function skipUntilFixed(int $issue): void
    {
        self::markTestSkipped(sprintf('Bug: https://github.com/Setono/SyliusShipmondoPlugin/issues/%d', $issue));
    }

    protected static function id(object $resource): int
    {
        Assert::methodExists($resource, 'getId');
        $id = $resource->getId();
        Assert::integer($id);

        return $id;
    }
}
