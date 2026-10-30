<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Security\Core\User\UserInterface;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\TestApplication;
use Webmozart\Assert\Assert;

/**
 * Boots the real test application against MySQL (DATABASE_URL), starting from the baseline shop
 * ({@see ShopFixtures}), with the Shipmondo API stubbed at the HTTP boundary: the plugin's
 * `setono_sylius_shipmondo.client` service is replaced with a real SDK client whose PSR-18 transport is
 * {@see ShipmondoApiStub}.
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

        // must happen before any service that depends on the client is instantiated
        $this->shipmondo = new ShipmondoApiStub();
        static::getContainer()->set('setono_sylius_shipmondo.client', $this->shipmondo->client());

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

        if (null !== $restoreFailure) {
            throw $restoreFailure;
        }

        self::assertSame([], $unexpectedRequests, 'The test made Shipmondo requests it did not stub');
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
