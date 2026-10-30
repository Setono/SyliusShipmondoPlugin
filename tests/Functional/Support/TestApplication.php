<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Support;

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Http\Discovery\Psr18ClientDiscovery;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Webmozart\Assert\Assert;

/**
 * Prepares the test application for the functional suite.
 *
 * Every test runs inside a transaction that dama/doctrine-test-bundle rolls back afterwards. What all tests
 * share is committed once per PHPUnit run, outside that transaction (MySQL commits DDL implicitly):
 *
 * - the schema of the configured DATABASE_URL is (re)created, so the suite only needs a reachable MySQL server
 * - the baseline data every test starts from ({@see ShopFixtures::createBaseline()}) is created
 *
 * It also makes sure no real HTTP client can be discovered. And because the admin and shop layouts load their
 * assets through Webpack Encore, which fails when the frontend has not been built (CI does not run yarn),
 * minimal empty Encore builds are written for the run in that case and removed again when it ends.
 */
final class TestApplication
{
    private const ENCORE_BUILDS = [
        'admin' => 'admin-entry',
        'shop' => 'shop-entry',
    ];

    private static bool $prepared = false;

    public static function prepare(KernelInterface $kernel, ContainerInterface $container): void
    {
        if (self::$prepared) {
            return;
        }

        Psr18ClientDiscovery::prependStrategy(NoNetworkHttpClientStrategy::class);

        self::stubMissingEncoreBuilds($kernel->getProjectDir() . '/public/build');

        self::outsideTestTransaction($container, static function () use ($kernel, $container): void {
            $application = new Application($kernel);
            $application->setAutoExit(false);

            self::runCommand($application, 'doctrine:database:create', ['--if-not-exists' => true]);
            self::runCommand($application, 'doctrine:schema:drop', ['--force' => true, '--full-database' => true]);
            self::runCommand($application, 'doctrine:schema:create');

            (new ShopFixtures($container))->createBaseline();
        });

        self::$prepared = true;
    }

    /**
     * For tests that commit for real: empties every table and recreates the baseline, so the tests after them
     * start from the same data as all others
     */
    public static function restoreBaseline(ContainerInterface $container): void
    {
        self::outsideTestTransaction($container, static function () use ($container): void {
            $connection = self::connection($container);
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

            try {
                foreach ($connection->createSchemaManager()->listTableNames() as $table) {
                    $connection->executeStatement(sprintf('TRUNCATE TABLE %s', $connection->quoteIdentifier($table)));
                }
            } finally {
                $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            }

            (new ShopFixtures($container))->createBaseline();
        });
    }

    /**
     * Runs $work on a connection of its own, which DAMA does not wrap in its per-test transaction, so whatever it
     * does is committed. Afterwards the application's connection reconnects as it was configured before.
     */
    private static function outsideTestTransaction(ContainerInterface $container, \Closure $work): void
    {
        $connection = self::connection($container);
        $keepStaticConnections = StaticDriver::isKeepStaticConnections();

        // a failed test can leave pending changes (or a closed entity manager) behind; they must not be flushed here
        self::freshEntityManager($container);
        $connection->close();
        StaticDriver::setKeepStaticConnections(false);

        // it can also leave the connection marked rollback-only, which DBAL 3 keeps across close(); rolling back a
        // transaction of its own is what resets that
        $connection->beginTransaction();
        $connection->rollBack();

        try {
            $work();
        } finally {
            $connection->close();
            self::freshEntityManager($container);

            StaticDriver::setKeepStaticConnections($keepStaticConnections);
        }
    }

    private static function freshEntityManager(ContainerInterface $container): void
    {
        $registry = $container->get('doctrine');
        Assert::isInstanceOf($registry, ManagerRegistry::class);

        $entityManager = $registry->getManager();
        Assert::isInstanceOf($entityManager, EntityManagerInterface::class);

        if (!$entityManager->isOpen()) {
            $registry->resetManager();

            return;
        }

        $entityManager->clear();
    }

    private static function connection(ContainerInterface $container): Connection
    {
        $connection = $container->get('doctrine.dbal.default_connection');
        Assert::isInstanceOf($connection, Connection::class);

        return $connection;
    }

    /**
     * @param array<string, bool> $options
     */
    private static function runCommand(Application $application, string $command, array $options = []): void
    {
        $output = new BufferedOutput();
        $exitCode = $application->run(new ArrayInput(['command' => $command, '--no-interaction' => true] + $options), $output);

        if (0 !== $exitCode) {
            throw new \RuntimeException(sprintf(
                'Could not prepare the functional test database. "%s" failed: %s',
                $command,
                $output->fetch(),
            ));
        }
    }

    private static function stubMissingEncoreBuilds(string $buildDir): void
    {
        $created = [];

        foreach (self::ENCORE_BUILDS as $build => $entry) {
            $dir = $buildDir . '/' . $build;
            if (is_file($dir . '/entrypoints.json')) {
                continue;
            }

            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Could not create the directory "%s"', $dir));
            }

            file_put_contents($dir . '/entrypoints.json', json_encode(['entrypoints' => [$entry => ['js' => [], 'css' => []]]], \JSON_THROW_ON_ERROR));
            file_put_contents($dir . '/manifest.json', '{}');

            $created[] = $dir;
        }

        if ([] === $created) {
            return;
        }

        register_shutdown_function(static function () use ($created, $buildDir): void {
            foreach ($created as $dir) {
                @unlink($dir . '/entrypoints.json');
                @unlink($dir . '/manifest.json');
                @rmdir($dir);
            }

            @rmdir($buildDir);
        });
    }
}
