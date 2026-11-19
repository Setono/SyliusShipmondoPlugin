<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\DependencyInjection\SetonoSyliusShipmondoExtension;
use Symfony\Bundle\FrameworkBundle\DependencyInjection\Configuration;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Webmozart\Assert\Assert;

/**
 * How the HTTP clients the plugin prepends merge with a shop's own framework config, as FrameworkBundle processes the
 * config files: the plugin's prepended config first, then the shop's file
 */
final class ShipmondoHttpClientsConfigurationTest extends TestCase
{
    private const HTTP_CLIENT = 'setono_sylius_shipmondo.http_client';

    private const PICKUP_POINTS_HTTP_CLIENT = 'setono_sylius_shipmondo.pickup_points_http_client';

    /**
     * @test
     */
    public function the_plugins_clients_limit_requests_to_shipmondo_and_do_not_retry(): void
    {
        $clients = self::scopedClients([]);

        self::assertSame('https://(app|sandbox)\.shipmondo\.com/', $clients[self::HTTP_CLIENT]['scope']);
        self::assertSame(10, $clients[self::HTTP_CLIENT]['timeout']);
        self::assertSame(30, $clients[self::HTTP_CLIENT]['max_duration']);
        self::assertFalse(self::retries($clients[self::HTTP_CLIENT]));

        self::assertSame('https://(app|sandbox)\.shipmondo\.com/', $clients[self::PICKUP_POINTS_HTTP_CLIENT]['scope']);
        self::assertSame(5, $clients[self::PICKUP_POINTS_HTTP_CLIENT]['timeout']);
        self::assertSame(10, $clients[self::PICKUP_POINTS_HTTP_CLIENT]['max_duration']);
        self::assertFalse(self::retries($clients[self::PICKUP_POINTS_HTTP_CLIENT]));
    }

    /**
     * @test
     */
    public function a_shop_overrides_one_limit_and_keeps_the_rest(): void
    {
        $clients = self::scopedClients([
            'scoped_clients' => [
                self::HTTP_CLIENT => ['max_duration' => 60],
            ],
        ]);

        self::assertSame(60, $clients[self::HTTP_CLIENT]['max_duration']);
        self::assertSame('https://(app|sandbox)\.shipmondo\.com/', $clients[self::HTTP_CLIENT]['scope']);
        self::assertSame(10, $clients[self::HTTP_CLIENT]['timeout']);
        self::assertFalse(self::retries($clients[self::HTTP_CLIENT]));

        // the other client is untouched
        self::assertSame(5, $clients[self::PICKUP_POINTS_HTTP_CLIENT]['timeout']);
        self::assertSame(10, $clients[self::PICKUP_POINTS_HTTP_CLIENT]['max_duration']);
    }

    /**
     * @test
     *
     * @dataProvider defaultRetries
     *
     * @param bool|array<string, mixed> $retryFailed
     */
    public function a_shops_default_retry_alone_does_not_reach_the_plugins_clients(bool|array $retryFailed): void
    {
        $clients = self::scopedClients([
            'default_options' => ['retry_failed' => $retryFailed],
        ]);

        self::assertFalse(self::retries($clients[self::HTTP_CLIENT]));
        self::assertFalse(self::retries($clients[self::PICKUP_POINTS_HTTP_CLIENT]));
    }

    /**
     * @return iterable<string, array{bool|array<string, mixed>}>
     */
    public static function defaultRetries(): iterable
    {
        yield 'turned on' => [true];
        yield 'configured' => [['max_retries' => 3]];
    }

    /**
     * Symfony copies the default retry onto the scoped clients named in the same config file, whatever the plugin
     * prepends. UPGRADE.md tells such a shop to repeat "retry_failed: false" there
     *
     * @test
     */
    public function a_shop_that_sets_a_default_retry_and_overrides_a_plugin_client_in_the_same_file_makes_that_client_retry(): void
    {
        $clients = self::scopedClients([
            'default_options' => ['retry_failed' => ['max_retries' => 3]],
            'scoped_clients' => [
                self::HTTP_CLIENT => ['max_duration' => 60],
            ],
        ]);

        self::assertTrue(self::retries($clients[self::HTTP_CLIENT]));
        self::assertFalse(self::retries($clients[self::PICKUP_POINTS_HTTP_CLIENT]));
    }

    /**
     * @test
     */
    public function a_shop_that_repeats_that_the_client_does_not_retry_keeps_it_from_retrying(): void
    {
        $clients = self::scopedClients([
            'default_options' => ['retry_failed' => ['max_retries' => 3]],
            'scoped_clients' => [
                self::HTTP_CLIENT => ['max_duration' => 60, 'retry_failed' => false],
            ],
        ]);

        self::assertFalse(self::retries($clients[self::HTTP_CLIENT]));
    }

    /**
     * The scoped HTTP clients FrameworkBundle configures from the plugin's prepended config and the shop's
     * framework.http_client config
     *
     * @param array<string, mixed> $shopsHttpClient
     *
     * @return array<string, array<string, mixed>>
     */
    private static function scopedClients(array $shopsHttpClient): array
    {
        $container = new ContainerBuilder();
        (new SetonoSyliusShipmondoExtension())->prepend($container);

        $prepended = $container->getExtensionConfig('framework')[0];
        Assert::isArray($prepended);

        $configs = [['http_client' => $prepended['http_client']]];
        if ([] !== $shopsHttpClient) {
            $configs[] = ['http_client' => $shopsHttpClient];
        }

        $config = (new Processor())->processConfiguration(new Configuration(false), $configs);
        Assert::isArray($config['http_client']);
        Assert::isArray($config['http_client']['scoped_clients']);

        /** @var array<string, array<string, mixed>> $scopedClients */
        $scopedClients = $config['http_client']['scoped_clients'];

        return $scopedClients;
    }

    /**
     * @param array<string, mixed> $client
     */
    private static function retries(array $client): bool
    {
        Assert::isArray($client['retry_failed']);
        Assert::boolean($client['retry_failed']['enabled']);

        return $client['retry_failed']['enabled'];
    }
}
