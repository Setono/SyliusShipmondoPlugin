<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Playwright;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Playwright\Locator\LocatorInterface;
use Playwright\Page\PageInterface;
use Playwright\Symfony\Test\PlaywrightTestCase;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Shipping\Calculator\DefaultCalculators;
use Symfony\Component\HttpFoundation\Request;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\TestApplication;
use Webmozart\Assert\Assert;

/**
 * Browser tests: a real Chromium (Playwright) drives the test application, and every request the browser makes
 * to http://localhost is handled in this PHP process by the same kernel the test uses (playwright-php/playwright-symfony).
 *
 * The setup is the functional suite's (tests/Functional): the baseline shop built through Sylius's own factories
 * ({@see ShopFixtures}), every test inside a transaction dama/doctrine-test-bundle rolls back afterwards (the
 * browser's requests are handled on the same connection, so they see the test's data), and the Shipmondo API
 * stubbed at the HTTP boundary ({@see ShipmondoApiStub}), so the plugin and the Shipmondo SDK run for real and
 * nothing reaches Shipmondo. Unlike the functional suite the pages run their JavaScript, so the test application's
 * assets must be built.
 *
 * The shop has two shipping methods to the customer's country: "UPS" (selected by default) and "GLS pickup point",
 * which delivers to GLS pickup points.
 *
 * TODO: playwright-php/playwright-symfony requires PHP >= 8.2 while the plugin still supports 8.1, so it is only
 * installed in the CI job that runs this suite. When the PHP requirement is raised to >= 8.2, add it to require-dev
 * and run this suite in the "Integration tests" job next to the functional suite.
 */
abstract class BrowserTestCase extends PlaywrightTestCase
{
    public const REGULAR_SHIPPING_METHOD_CODE = 'ups';

    public const REGULAR_SHIPPING_METHOD_NAME = 'UPS';

    public const PICKUP_POINT_SHIPPING_METHOD_NAME = 'GLS pickup point';

    protected ShipmondoApiStub $shipmondo;

    protected ShopFixtures $fixtures;

    protected ShippingMethodInterface $regularShippingMethod;

    /** @var list<Request> */
    private array $handledRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        static::bootKernel();
        $kernel = static::$kernel;
        Assert::notNull($kernel);

        foreach (['admin', 'shop'] as $build) {
            $entrypoints = sprintf('%s/public/build/%s/entrypoints.json', $kernel->getProjectDir(), $build);
            if (!is_file($entrypoints) || !str_contains((string) file_get_contents($entrypoints), '.js')) {
                self::fail('The browser tests need the test application\'s assets: run "yarn install && yarn build" in tests/Application');
            }
        }

        $this->shipmondo = new ShipmondoApiStub();
        $this->installShipmondoStub();

        TestApplication::prepare($kernel, static::getContainer());

        $this->fixtures = (new ShopFixtures(static::getContainer()))->load();
        $this->regularShippingMethod = $this->createRegularShippingMethod();
        $this->handledRequests = [];
    }

    protected function tearDown(): void
    {
        $unexpectedRequests = $this->shipmondo->unexpectedRequests();

        parent::tearDown();

        self::assertSame([], $unexpectedRequests, 'The test made Shipmondo requests it did not stub');
    }

    /**
     * Runs before the kernel handles a request of the browser.
     *
     * Like Symfony's KernelBrowser does by default, every request after the first is handled by a fresh container,
     * as on a web server. Reusing the container leaks state between requests: e.g. the entity manager is cleared
     * between requests while services still hold entities, so adding to the cart fails with "A new entity was
     * found through the relationship Order#channel". The database connection, and so the test's transaction, is
     * static and survives the reboot (dama/doctrine-test-bundle).
     */
    public function beforeRequest(Request $request): void
    {
        if ([] !== $this->handledRequests) {
            $kernel = static::$kernel;
            Assert::notNull($kernel);
            $kernel->boot();
            $kernel->shutdown();
            gc_collect_cycles();
            $kernel->boot();
            $this->installShipmondoStub();
        }

        $this->handledRequests[] = $request;
    }

    /**
     * Replaces the plugin's Shipmondo client with a real SDK client whose HTTP transport is the stub. Must happen
     * before any service that depends on the client is instantiated.
     */
    private function installShipmondoStub(): void
    {
        static::getContainer()->set('setono_sylius_shipmondo.client', $this->shipmondo->client());
    }

    /**
     * The requests the browser made to the application whose path ends with the given suffix
     *
     * @return list<Request>
     */
    protected function handledRequests(string $pathSuffix): array
    {
        return array_values(array_filter(
            $this->handledRequests,
            static fn (Request $request): bool => str_ends_with($request->getPathInfo(), $pathSuffix),
        ));
    }

    protected function logInAsAdministrator(): void
    {
        $this->loginUser($this->fixtures->admin(), 'admin');
    }

    /**
     * Answers a paginated Shipmondo list endpoint, e.g. "payment_gateways", with the response in Support/shipmondo
     *
     * @param list<array<string, mixed>>|null $items the items, or null for the ones in Support/shipmondo/<path>.json
     */
    protected function respondWithList(string $path, ?array $items = null): void
    {
        $items ??= self::shipmondoResponse($path);

        $this->shipmondo->respond('GET', $path, 200, $items, [
            'X-Current-Page' => '1',
            'X-Per-Page' => '20',
            'X-Total-Count' => (string) count($items),
            'X-Total-Pages' => '1',
        ]);
    }

    /**
     * A response body captured from (or modelled on) the Shipmondo API, see Support/shipmondo
     *
     * @return list<array<string, mixed>>
     */
    protected static function shipmondoResponse(string $name): array
    {
        $json = file_get_contents(sprintf('%s/Support/shipmondo/%s.json', __DIR__, $name));
        Assert::string($json);

        /** @var list<array<string, mixed>> $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * A Sylius flash message
     *
     * @param 'success'|'error'|'info' $type
     */
    protected function flash(string $type): LocatorInterface
    {
        return $this->getPage()->locator(sprintf('.sylius-flash-message.%s', ['success' => 'positive', 'error' => 'negative', 'info' => 'info'][$type]));
    }

    /**
     * Navigates and waits until the page has loaded, see {@see self::waitUntilLoaded()}
     */
    protected function visit(string $path): PageInterface
    {
        $page = parent::visit($path);
        $this->waitUntilLoaded();

        return $page;
    }

    /**
     * Clicks something that navigates (a link, or the submit button of a form, which in Sylius redirects after the
     * POST), follows any redirects and waits until the page has loaded.
     *
     * playwright-php only follows a redirect of a navigation started by a click once the click has timed out, so
     * the browser is stopped on the redirect instead (an empty page) and the redirect is followed from here.
     */
    protected function clickAndFollowRedirects(LocatorInterface $locator): void
    {
        $client = static::getPlaywrightClient();
        $client->followRedirects(false);

        try {
            $locator->click();

            while (true) {
                try {
                    $client->followRedirect();
                } catch (\LogicException) {
                    // the last response was not a redirect
                    break;
                }
            }
        } finally {
            $client->followRedirects(true);
        }

        $this->waitUntilLoaded();
    }

    /**
     * Waits until the page and its assets have loaded, asking with quick evaluate() calls only.
     *
     * The browser's requests are answered by this process, in between the Playwright commands it sends. The
     * Playwright server runs the commands it reads in one go one after the other, so a command that waits for the
     * page (a click, an auto-waiting check) can end up queued in front of the answer to a request the page is still
     * waiting for, and then both wait until the command times out. Starting such commands only once the page has
     * loaded avoids that.
     */
    protected function waitUntilLoaded(): void
    {
        $this->waitUntil('() => document.readyState === "complete"', 'The page did not finish loading');
    }

    /**
     * Waits until the JavaScript function returns true, asking with quick evaluate() calls only (so the page's
     * requests keep being answered, see {@see self::waitUntilLoaded()})
     */
    protected function waitUntil(string $condition, string $failure, float $timeoutSeconds = 30): void
    {
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            try {
                if (true === $this->getPage()->evaluate($condition)) {
                    return;
                }
            } catch (\Throwable) {
                // the page is in the middle of navigating
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail(sprintf('%s (%s)', $failure, $this->getPage()->url()));
    }

    /**
     * Lets the page run for a while, answering the requests it makes, e.g. to see that something does not happen
     */
    protected function settle(float $seconds = 1.0): void
    {
        $until = microtime(true) + $seconds;

        do {
            $this->getPage()->evaluate('() => true');
            usleep(50_000);
        } while (microtime(true) < $until);
    }

    /**
     * Clicks a button guarded by Sylius's "Are you sure?" confirmation modal and confirms
     */
    protected function clickAndConfirm(LocatorInterface $button): void
    {
        $button->click();
        $this->clickAndFollowRedirects($this->getPage()->locator('#confirmation-button'));
    }

    /**
     * Picks an option in a Semantic UI dropdown (the admin enhances <select> elements with it)
     */
    protected static function pickDropdownOption(LocatorInterface $dropdown, string $option): void
    {
        $dropdown->click();
        $dropdown->locator('.menu .item')->filter(['hasText' => $option])->click();
    }

    protected function createCustomer(): CustomerInterface
    {
        return $this->fixtures->createCustomer(sprintf('customer-%s@example.com', bin2hex(random_bytes(4))));
    }

    /**
     * A placed order in the given Shipmondo state, optionally with a Shipmondo id (i.e. uploaded to Shipmondo)
     */
    protected function createOrder(string $shipmondoState = OrderInterface::SHIPMONDO_STATE_PENDING, ?int $shipmondoId = null): OrderInterface
    {
        $order = $this->fixtures->createOrder($this->createCustomer());
        $order->setShipmondoState($shipmondoState);
        $order->setShipmondoId($shipmondoId);
        $this->entityManager()->flush();

        return $order;
    }

    protected function createPaymentMethod(string $code, string $name): PaymentMethodInterface
    {
        $factory = static::getContainer()->get('sylius.factory.payment_method');
        Assert::isInstanceOf($factory, PaymentMethodFactoryInterface::class);

        $paymentMethod = $factory->createWithGateway('offline');
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        $paymentMethod->getGatewayConfig()?->setGatewayName('offline');
        $paymentMethod->setCode($code);
        $paymentMethod->setCurrentLocale(ShopFixtures::LOCALE_CODE);
        $paymentMethod->setFallbackLocale(ShopFixtures::LOCALE_CODE);
        $paymentMethod->setName($name);
        $paymentMethod->addChannel($this->fixtures->channel);
        $paymentMethod->setEnabled(true);

        $this->entityManager()->persist($paymentMethod);
        $this->entityManager()->flush();

        return $paymentMethod;
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
     * Reads an order's Sylius state and Shipmondo id straight from the database
     *
     * @return array{state: string, shipmondo_id: int|null}
     */
    protected function orderRow(int $orderId): array
    {
        $row = $this->connection()->fetchAssociative('SELECT state, shipmondoId FROM sylius_order WHERE id = ?', [$orderId]);
        Assert::isArray($row, sprintf('The order %d does not exist', $orderId));
        Assert::string($row['state']);

        return ['state' => $row['state'], 'shipmondo_id' => null === $row['shipmondoId'] ? null : (int) $row['shipmondoId']];
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

    private function createRegularShippingMethod(): ShippingMethodInterface
    {
        $factory = static::getContainer()->get('sylius.factory.shipping_method');
        Assert::isInstanceOf($factory, FactoryInterface::class);

        $shippingMethod = $factory->createNew();
        Assert::isInstanceOf($shippingMethod, ShippingMethodInterface::class);
        $shippingMethod->setCode(self::REGULAR_SHIPPING_METHOD_CODE);
        $shippingMethod->setCurrentLocale(ShopFixtures::LOCALE_CODE);
        $shippingMethod->setFallbackLocale(ShopFixtures::LOCALE_CODE);
        $shippingMethod->setName(self::REGULAR_SHIPPING_METHOD_NAME);
        $shippingMethod->setZone($this->fixtures->shippingMethod->getZone());
        $shippingMethod->setCalculator(DefaultCalculators::FLAT_RATE);
        $shippingMethod->setConfiguration([ShopFixtures::CHANNEL_CODE => ['amount' => 900]]);
        $shippingMethod->addChannel($this->fixtures->channel);
        $shippingMethod->setEnabled(true);

        $this->entityManager()->persist($shippingMethod);
        $this->entityManager()->flush();

        // listed (and so selected by default) before the pickup point shipping method. Set in the database because
        // the position is a Gedmo sortable position, which Gedmo assigns itself
        $this->connection()->executeStatement(
            'UPDATE sylius_shipping_method SET position = IF(code = ?, 0, 1)',
            [self::REGULAR_SHIPPING_METHOD_CODE],
        );

        return $shippingMethod;
    }
}
