<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Setono\SyliusShipmondoPlugin\Command\RegisterWebhooksCommand;
use Setono\SyliusShipmondoPlugin\Registrar\UnreachableWebhookEndpointException;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Sylius\Component\Channel\Model\Channel;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Translations;

final class RegisterWebhooksCommandTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<WebhookRegistrarInterface> */
    private ObjectProphecy $webhookRegistrar;

    /** @var ObjectProphecy<ChannelRepositoryInterface> */
    private ObjectProphecy $channelRepository;

    /**
     * framework.router.default_uri, or http://localhost, FrameworkBundle's default, when the app doesn't set it
     */
    private string $defaultUri = 'http://localhost';

    /**
     * The router's context on the command line, built from the default URI
     */
    private RequestContext $routerContext;

    private UrlGenerator $urlGenerator;

    private RecordingLogger $logger;

    /**
     * The endpoint the webhooks are registered with: the one the router generates while the registrar registers them
     *
     * @var list<string>
     */
    private array $registeredEndpoints = [];

    protected function setUp(): void
    {
        $this->webhookRegistrar = $this->prophesize(WebhookRegistrarInterface::class);
        $this->channelRepository = $this->prophesize(ChannelRepositoryInterface::class);
        $this->channelRepository->findEnabled()->willReturn([]);
        $this->logger = new RecordingLogger();

        $routes = new RouteCollection();
        $routes->add('_webhook_controller', new Route('/webhook/{type}'));
        $this->routerContext = RequestContext::fromUri($this->defaultUri);
        $this->urlGenerator = new UrlGenerator($routes, $this->routerContext);
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_under_the_channels_hostname(): void
    {
        $this->channels('shop.example.com');
        $this->registrarRegistersTheWebhooks();

        $command = $this->command();
        $commandTester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertSame(['https://shop.example.com/webhook/shipmondo'], $this->registeredEndpoints);
        $this->assertTheRouterContextIsPutBack();
        self::assertSame([], $this->logger->records);
        self::assertSame('setono:sylius-shipmondo:register-webhooks', $command->getName());
    }

    /**
     * @test
     *
     * @dataProvider unreachableChannelHostnames
     */
    public function it_skips_a_channel_hostname_that_shipmondo_cannot_reach(?string $unreachableHostname): void
    {
        $this->channels($unreachableHostname, 'shop.example.com');
        $this->registrarRegistersTheWebhooks();

        self::assertSame(Command::SUCCESS, (new CommandTester($this->command()))->execute([], ['interactive' => false]));
        self::assertSame(['https://shop.example.com/webhook/shipmondo'], $this->registeredEndpoints);
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function unreachableChannelHostnames(): iterable
    {
        yield 'none' => [null];

        yield from self::unreachableHostnames();
    }

    /**
     * Hostnames that name the shop's own machine, which Shipmondo can't reach, and hostnames that are not hostnames
     *
     * @return iterable<string, array{string}>
     */
    public static function unreachableHostnames(): iterable
    {
        yield 'empty' => [''];
        yield 'localhost' => ['localhost'];
        yield 'localhost in capitals' => ['LocalHost'];
        yield 'localhost, fully qualified' => ['localhost.'];
        yield 'a name under localhost' => ['shop.localhost'];
        yield 'a name under localhost, fully qualified' => ['shop.localhost.'];
        yield 'a loopback address' => ['127.0.0.1'];
        yield 'another loopback address' => ['127.1.2.3'];
        yield 'with a port' => ['shop.example.se:8000'];
        yield 'a URL' => ['https://shop.example.se'];
    }

    /**
     * @test
     */
    public function it_registers_a_hostname_on_https_without_the_port_of_the_routers_default_uri(): void
    {
        // e.g. the address `symfony serve` serves a local app at
        $this->defaultUri('https://127.0.0.1:8000');
        $this->channels('shop.example.com');
        $this->registrarRegistersTheWebhooks();

        self::assertSame(Command::SUCCESS, (new CommandTester($this->command()))->execute([]));
        self::assertSame(['https://shop.example.com/webhook/shipmondo'], $this->registeredEndpoints);
        $this->assertTheRouterContextIsPutBack();
    }

    /**
     * @test
     */
    public function it_registers_a_hostname_at_its_root_without_the_path_of_the_routers_default_uri(): void
    {
        $this->defaultUri('https://dev.example.com/shop');
        $this->channels('shop.example.com');
        $this->registrarRegistersTheWebhooks();

        self::assertSame(Command::SUCCESS, (new CommandTester($this->command()))->execute([]));
        self::assertSame(['https://shop.example.com/webhook/shipmondo'], $this->registeredEndpoints);
        $this->assertTheRouterContextIsPutBack();
    }

    /**
     * @test
     */
    public function it_counts_a_hostname_once_when_several_channels_have_it(): void
    {
        // hostnames are case-insensitive
        $this->channels('shop.example.com', 'shop.example.com', 'Shop.Example.COM');
        $this->registrarRegistersTheWebhooks();

        self::assertSame(Command::SUCCESS, (new CommandTester($this->command()))->execute([], ['interactive' => false]));
        self::assertSame(['https://shop.example.com/webhook/shipmondo'], $this->registeredEndpoints);
    }

    /**
     * @test
     */
    public function it_fails_without_registering_when_the_channels_have_several_hostnames_and_it_cannot_ask_which(): void
    {
        $this->channels('shop.example.com', 'shop.example.se');
        $this->registrarRegistersTheWebhooks();

        $commandTester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $commandTester->execute([], ['interactive' => false, 'capture_stderr_separately' => true]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertStringContainsString(
            '[ERROR] The channels have several hostnames: shop.example.com, shop.example.se. Choose the one Shipmondo sends the webhooks to with --hostname.',
            self::oneLine($commandTester->getErrorOutput()),
        );
        self::assertSame([], $this->registeredEndpoints);
        $this->assertTheRouterContextIsPutBack();
    }

    /**
     * @test
     */
    public function it_asks_which_hostname_when_the_channels_have_several(): void
    {
        // each hostname is offered once, also when several channels have it
        $this->channels('shop.example.com', 'shop.example.com', 'shop.example.se');
        $this->registrarRegistersTheWebhooks();

        $commandTester = new CommandTester($this->command());
        $commandTester->setInputs(['shop.example.se']);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        $display = self::oneLine($commandTester->getDisplay());
        self::assertStringContainsString('Which hostname does Shipmondo send the webhooks to?', $display);
        self::assertStringContainsString('[0] shop.example.com [1] shop.example.se', $display);
        self::assertSame(['https://shop.example.se/webhook/shipmondo'], $this->registeredEndpoints);
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_under_the_hostname_it_is_given_instead_of_the_channels(): void
    {
        $this->channels('shop.example.com', 'shop.example.se');
        $this->registrarRegistersTheWebhooks();

        $commandTester = new CommandTester($this->command());

        self::assertSame(Command::SUCCESS, $commandTester->execute(['--hostname' => 'webhooks.example.com']));
        self::assertSame('', $commandTester->getDisplay());
        self::assertSame(['https://webhooks.example.com/webhook/shipmondo'], $this->registeredEndpoints);
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_under_the_routers_default_uri_when_no_channel_has_a_hostname_shipmondo_can_reach(): void
    {
        $this->channels(null, 'localhost');
        $this->defaultUri('http://shop.example.com:8080');
        $this->registrarRegistersTheWebhooks();

        self::assertSame(Command::SUCCESS, (new CommandTester($this->command()))->execute([]));
        self::assertSame(['http://shop.example.com:8080/webhook/shipmondo'], $this->registeredEndpoints);
        $this->assertTheRouterContextIsPutBack();
    }

    /**
     * @test
     *
     * @dataProvider unreachableDefaultUris
     */
    public function it_fails_without_registering_when_neither_a_channel_nor_the_routers_default_uri_has_a_hostname_shipmondo_can_reach(string $defaultUri, string $host): void
    {
        $this->channels(null, 'localhost');
        $this->defaultUri($defaultUri);
        $this->registrarRegistersTheWebhooks();

        $commandTester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $commandTester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertStringContainsString(
            sprintf('[ERROR] Found no hostname Shipmondo can reach to send the webhooks to: no channel has one, and the router\'s default URI (framework.router.default_uri) has %s. Give a channel the shop\'s hostname, or pass it with --hostname.', $host),
            self::oneLine($commandTester->getErrorOutput()),
        );
        self::assertSame([], $this->registeredEndpoints);
        $this->assertTheRouterContextIsPutBack();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreachableDefaultUris(): iterable
    {
        // FrameworkBundle's default, when the app doesn't set framework.router.default_uri
        yield 'none' => ['http://localhost', 'localhost'];
        yield 'a loopback address' => ['https://127.0.0.1:8000', '127.0.0.1'];
        yield 'an IPv6 loopback address' => ['http://[::1]', '[::1]'];
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_under_a_name_that_only_starts_like_a_loopback_address(): void
    {
        $this->registrarRegistersTheWebhooks();

        self::assertSame(Command::SUCCESS, (new CommandTester($this->command()))->execute(['--hostname' => '127.example.com']));
        self::assertSame(['https://127.example.com/webhook/shipmondo'], $this->registeredEndpoints);
    }

    /**
     * @test
     *
     * @dataProvider unreachableHostnames
     */
    public function it_fails_without_registering_when_it_is_given_a_hostname_shipmondo_cannot_reach(string $hostname): void
    {
        $this->channels('shop.example.com');
        $this->registrarRegistersTheWebhooks();

        $commandTester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $commandTester->execute(['--hostname' => $hostname], ['capture_stderr_separately' => true]));
        self::assertStringContainsString(
            sprintf('[ERROR] Shipmondo can\'t reach the hostname "%s". Pass the shop\'s hostname, e.g. --hostname=shop.example.com.', $hostname),
            self::oneLine($commandTester->getErrorOutput()),
        );
        self::assertSame([], $this->registeredEndpoints);
    }

    /**
     * @test
     */
    public function it_fails_and_explains_why_when_shipmondo_fails(): void
    {
        $this->channels('shop.example.com');
        $exception = new UnauthorizedException(ShipmondoApi::json(['error' => 'Invalid credentials'], 401));
        $this->webhookRegistrar->register()->willThrow($exception);

        $commandTester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $commandTester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertStringContainsString(
            '[ERROR] The webhooks could not be registered, so Shipmondo may send only some of them, or none. Register them again once the problem is solved. Shipmondo did not accept the API credentials.',
            self::oneLine($commandTester->getErrorOutput()),
        );
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'The webhooks could not be registered in Shipmondo',
            'context' => [
                'exception' => $exception,
                'shipmondoFailure' => 'credentials_failure',
                'shipmondoStatusCode' => 401,
                'shipmondoError' => 'Invalid credentials',
            ],
        ]], $this->logger->records);
        $this->assertTheRouterContextIsPutBack();
    }

    /**
     * The command registers only at a hostname Shipmondo can reach, so only a registrar that judges the endpoint
     * otherwise refuses it, e.g. one an app decorated
     *
     * @test
     */
    public function it_fails_and_explains_why_when_the_registrar_refuses_the_endpoint(): void
    {
        $this->channels('shop.example.com');
        $this->webhookRegistrar->register()->willThrow(new UnreachableWebhookEndpointException('https://shop.example.com/webhook/shipmondo'));

        $commandTester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $commandTester->execute([], ['capture_stderr_separately' => true]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertStringContainsString(
            '[ERROR] The webhooks were not registered: Shipmondo can\'t reach their endpoint, https://shop.example.com/webhook/shipmondo.',
            self::oneLine($commandTester->getErrorOutput()),
        );
        self::assertSame([], $this->logger->records);
        $this->assertTheRouterContextIsPutBack();
    }

    /**
     * @test
     */
    public function it_lets_a_failure_that_is_not_shipmondos_through(): void
    {
        $this->channels('shop.example.com');
        $failure = new \LogicException('A bug');
        $this->webhookRegistrar->register()->willThrow($failure);

        try {
            (new CommandTester($this->command()))->execute([]);
            self::fail('The failure was not let through');
        } catch (\LogicException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->logger->records);
        $this->assertTheRouterContextIsPutBack();
    }

    private function command(): RegisterWebhooksCommand
    {
        return new RegisterWebhooksCommand(
            $this->webhookRegistrar->reveal(),
            $this->channelRepository->reveal(),
            $this->urlGenerator,
            Translations::translator(),
            $this->logger,
        );
    }

    /**
     * The enabled channels, with these hostnames
     */
    private function channels(?string ...$hostnames): void
    {
        $channels = [];
        foreach ($hostnames as $hostname) {
            $channel = new Channel();
            $channel->setHostname($hostname);
            $channels[] = $channel;
        }

        $this->channelRepository->findEnabled()->willReturn($channels);
    }

    /**
     * The router's context on the command line when the app sets framework.router.default_uri to this URI
     */
    private function defaultUri(string $uri): void
    {
        $this->defaultUri = $uri;
        $this->routerContext = RequestContext::fromUri($uri);
        $this->urlGenerator->setContext($this->routerContext);
    }

    /**
     * The router has its own context again, unchanged
     */
    private function assertTheRouterContextIsPutBack(): void
    {
        self::assertSame($this->routerContext, $this->urlGenerator->getContext(), 'The router context is put back');
        self::assertEquals(RequestContext::fromUri($this->defaultUri), $this->routerContext, 'The router context is unchanged');
    }

    private function registrarRegistersTheWebhooks(): void
    {
        $urlGenerator = $this->urlGenerator;
        $registeredEndpoints = &$this->registeredEndpoints;

        // Prophecy binds a callback to the double, so it captures what it needs instead of using $this
        $this->webhookRegistrar->register()->will(static function () use ($urlGenerator, &$registeredEndpoints): void {
            $registeredEndpoints[] = $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL);
        });
    }

    private static function oneLine(string $output): string
    {
        return (string) preg_replace('/\s+/', ' ', $output);
    }
}
