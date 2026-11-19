<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Command;

use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Webmozart\Assert\Assert;

final class RegisterWebhooksCommandTest extends FunctionalTestCase
{
    private const COMMAND = 'setono:sylius-shipmondo:register-webhooks';

    /**
     * @test
     */
    public function it_replaces_this_shops_webhooks_in_shipmondo_and_records_the_registration(): void
    {
        $endpoint = $this->webhookEndpoint();

        $this->shipmondo
            ->respond('GET', 'webhooks', 200, [
                self::webhook(1, 'Setono Sylius Shipmondo Plugin - [orders:create]', $endpoint),
                self::webhook(2, 'Another integration - [orders:create]', $endpoint),
                // another shop's on the same Shipmondo account, registered under the same name prefix
                self::webhook(3, 'Setono Sylius Shipmondo Plugin - [orders:create]', 'https://another-shop.example.com/webhook/shipmondo'),
            ], ['X-Total-Pages' => '1'])
            ->respond('DELETE', 'webhooks/1', 204, null)
            ->respond('POST', 'webhooks', 201, self::webhook(4, 'Setono Sylius Shipmondo Plugin - [orders:create]', $endpoint))
        ;

        $tester = $this->executeCommand(self::COMMAND);
        $tester->assertCommandIsSuccessful();
        self::assertSame('', $tester->getDisplay());

        // only this shop's webhooks are removed: not another integration's, nor another shop's
        self::assertCount(1, $this->shipmondo->requests('DELETE'));
        self::assertCount(1, $this->shipmondo->requests('DELETE', 'webhooks/1'));

        $registered = [];
        foreach ($this->shipmondo->requests('POST', 'webhooks') as $request) {
            $body = ShipmondoApiStub::jsonBody($request);
            self::assertIsString($body['name']);
            self::assertMatchesRegularExpression('/^Setono Sylius Shipmondo Plugin \[[0-9a-f]{8}\] - \[/', $body['name']);
            self::assertSame($endpoint, $body['endpoint']);
            self::assertSame($this->webhooksKey(), $body['key']);
            self::assertIsString($body['resource_name']);
            self::assertIsString($body['action']);
            $registered[] = sprintf('%s:%s', $body['resource_name'], $body['action']);
        }

        // exactly the webhooks the plugin's webhook handlers handle
        self::assertSame([
            'Orders:status_update',
            'Orders:create_shipment',
            'Orders:payment_captured',
            'Orders:payment_voided',
            'Orders:delete',
        ], $registered);

        self::assertSame([$this->registrar()->getVersion()], $this->registeredVersions());
    }

    /**
     * @test
     */
    public function it_updates_the_existing_registration_when_run_again(): void
    {
        $this->shipmondo
            ->respond('GET', 'webhooks', 200, [], ['X-Total-Pages' => '1'])
            ->respond('POST', 'webhooks', 201, self::webhook(3, 'Setono Sylius Shipmondo Plugin - [orders:create]'))
        ;

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();
        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        self::assertCount(10, $this->shipmondo->requests('POST', 'webhooks'));
        self::assertSame([$this->registrar()->getVersion()], $this->registeredVersions());
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_under_the_channels_hostname(): void
    {
        $this->fixtures->channel->setHostname('shop.example.com');
        $this->entityManager()->flush();

        $this->shipmondo
            ->respond('GET', 'webhooks', 200, [], ['X-Total-Pages' => '1'])
            ->respond('POST', 'webhooks', 201, self::webhook(1, 'Setono Sylius Shipmondo Plugin - [orders:create]'))
        ;

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        $endpoints = array_map(
            static fn ($request): mixed => ShipmondoApiStub::jsonBody($request)['endpoint'],
            $this->shipmondo->requests('POST', 'webhooks'),
        );
        self::assertSame(array_fill(0, 5, 'https://shop.example.com/webhook/shipmondo'), $endpoints);
    }

    /**
     * @test
     */
    public function it_fails_without_asking_shipmondo_when_it_finds_no_hostname_shipmondo_can_reach(): void
    {
        // the baseline's channel has the hostname localhost, and the router has the context FrameworkBundle gives it
        // on the command line when the app doesn't set framework.router.default_uri
        $router = static::getContainer()->get('router');
        Assert::isInstanceOf($router, UrlGeneratorInterface::class);
        $router->setContext(RequestContext::fromUri('http://localhost'));

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('Found no hostname Shipmondo can reach to send the webhooks to', $display);
        self::assertSame([], $this->shipmondo->requests());
        self::assertSame([], $this->registeredVersions());
    }

    /**
     * @test
     */
    public function it_fails_and_explains_why_when_the_webhooks_cannot_be_registered(): void
    {
        $this->shipmondo->respond('GET', 'webhooks', 401, ['error' => 'Invalid credentials']);

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('The webhooks could not be registered', $display);
        self::assertStringContainsString('Shipmondo did not accept the API credentials', $display);
        self::assertSame([], $this->registeredVersions());
    }

    /**
     * @return array<string, mixed>
     */
    private static function webhook(int $id, string $name, string $endpoint = 'https://localhost/webhook/shipmondo'): array
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

    /**
     * @return list<string>
     */
    private function registeredVersions(): array
    {
        /** @var list<string> $versions */
        $versions = $this->database()->fetchFirstColumn('SELECT version FROM setono_sylius_shipmondo__registered_webhooks WHERE registeredAt IS NOT NULL');

        return $versions;
    }

    private function registrar(): WebhookRegistrarInterface
    {
        $registrar = static::getContainer()->get(WebhookRegistrarInterface::class);
        Assert::isInstanceOf($registrar, WebhookRegistrarInterface::class);

        return $registrar;
    }

    /**
     * Where Shipmondo sends this shop's webhooks
     */
    private function webhookEndpoint(): string
    {
        $urlGenerator = static::getContainer()->get('router');
        Assert::isInstanceOf($urlGenerator, UrlGeneratorInterface::class);

        return $urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function webhooksKey(): string
    {
        $key = static::getContainer()->getParameter('setono_sylius_shipmondo.webhooks.key');
        Assert::string($key);

        return $key;
    }
}
