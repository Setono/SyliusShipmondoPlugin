<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Support;

use Doctrine\DBAL\Connection;
use Http\Discovery\Psr18ClientDiscovery;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Prepares the test application for the functional suite:
 *
 * - once per PHPUnit run it (re)creates the database schema of the configured DATABASE_URL, so the suite
 *   only needs a reachable MySQL server, and makes sure no real HTTP client can be discovered
 * - before every test it empties all tables, so each test builds exactly the data it needs
 *
 * The admin and shop layouts load their assets through Webpack Encore, which fails when the frontend has not
 * been built (CI does not run yarn). In that case minimal, empty Encore builds are written for the run and
 * removed again when it ends.
 */
final class TestApplication
{
    private const ENCORE_BUILDS = [
        'admin' => 'admin-entry',
        'shop' => 'shop-entry',
    ];

    private static bool $prepared = false;

    /** @var list<string> */
    private static array $tables = [];

    public static function prepare(KernelInterface $kernel): void
    {
        if (self::$prepared) {
            return;
        }

        Psr18ClientDiscovery::prependStrategy(NoNetworkHttpClientStrategy::class);

        self::stubMissingEncoreBuilds($kernel->getProjectDir() . '/public/build');

        $application = new Application($kernel);
        $application->setAutoExit(false);

        self::runCommand($application, 'doctrine:database:create', ['--if-not-exists' => true]);
        self::runCommand($application, 'doctrine:schema:drop', ['--force' => true, '--full-database' => true]);
        self::runCommand($application, 'doctrine:schema:create');

        self::$prepared = true;
    }

    public static function purgeDatabase(Connection $connection): void
    {
        if ([] === self::$tables) {
            self::$tables = array_values($connection->createSchemaManager()->listTableNames());
        }

        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach (self::$tables as $table) {
                $connection->executeStatement(sprintf('TRUNCATE TABLE %s', $connection->quoteIdentifier($table)));
            }
        } finally {
            $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        }
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
