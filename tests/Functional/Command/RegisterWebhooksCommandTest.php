<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Command;

use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Webmozart\Assert\Assert;

final class RegisterWebhooksCommandTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_replaces_the_plugins_webhooks_in_shipmondo_and_records_the_registration(): void
    {
        $this->shipmondo
            ->respond('GET', 'webhooks', 200, [
                self::webhook(1, 'Setono Sylius Shipmondo Plugin - [orders:create]'),
                self::webhook(2, 'Another integration - [orders:create]'),
            ], ['X-Total-Pages' => '1'])
            ->respond('DELETE', 'webhooks/1', 204, null)
            ->respond('POST', 'webhooks', 201, self::webhook(3, 'Setono Sylius Shipmondo Plugin - [orders:create]'))
        ;

        self::assertSame(0, $this->runCommand());

        // only the plugin's own webhooks are removed
        self::assertCount(1, $this->shipmondo->requests('DELETE'));
        self::assertCount(1, $this->shipmondo->requests('DELETE', 'webhooks/1'));

        $registered = [];
        foreach ($this->shipmondo->requests('POST', 'webhooks') as $request) {
            $body = ShipmondoApiStub::jsonBody($request);
            self::assertIsString($body['name']);
            self::assertStringStartsWith('Setono Sylius Shipmondo Plugin - [', $body['name']);
            self::assertIsString($body['endpoint']);
            self::assertStringEndsWith('/webhook/shipmondo', $body['endpoint']);
            self::assertSame($this->webhooksKey(), $body['key']);
            self::assertIsString($body['resource_name']);
            self::assertIsString($body['action']);
            $registered[] = sprintf('%s:%s', $body['resource_name'], $body['action']);
        }

        self::assertSame([
            'Shipments:create',
            'Shipments:cancel',
            'Orders:create',
            'Orders:status_update',
            'Orders:create_fulfillment',
            'Orders:create_shipment',
            'Orders:payment_captured',
            'Orders:payment_voided',
            'Orders:delete',
            'Shipment Monitor:latest',
            'Shipment Monitor:delivered',
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

        self::assertSame(0, $this->runCommand());
        self::assertSame(0, $this->runCommand());

        self::assertCount(22, $this->shipmondo->requests('POST', 'webhooks'));
        self::assertSame([$this->registrar()->getVersion()], $this->registeredVersions());
    }

    /**
     * @return array<string, mixed>
     */
    private static function webhook(int $id, string $name): array
    {
        return [
            'id' => $id,
            'endpoint' => 'https://localhost/webhook/shipmondo',
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
        $versions = $this->observer()->fetchFirstColumn('SELECT version FROM setono_sylius_shipmondo__registered_webhooks WHERE registeredAt IS NOT NULL');

        return $versions;
    }

    private function registrar(): WebhookRegistrarInterface
    {
        $registrar = static::getContainer()->get('setono_sylius_shipmondo.registrar.webhook');
        Assert::isInstanceOf($registrar, WebhookRegistrarInterface::class);

        return $registrar;
    }

    private function webhooksKey(): string
    {
        $key = static::getContainer()->getParameter('setono_sylius_shipmondo.webhooks.key');
        Assert::string($key);

        return $key;
    }

    private function runCommand(): int
    {
        $kernel = static::$kernel;
        Assert::notNull($kernel);

        return (new CommandTester((new Application($kernel))->find('setono:sylius-shipmondo:register-webhooks')))->execute([]);
    }
}
