<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Request\Webhooks\Webhook as WebhookRequest;
use Setono\Shipmondo\Response\Webhooks\Webhook as WebhookResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function Symfony\Component\String\u;

final class WebhookRegistrar implements WebhookRegistrarInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $webhooksKey,
        private readonly string $namePrefix,
    ) {
    }

    public function register(): void
    {
        $this->client->webhooks()->deleteAll(
            fn (WebhookResponse $webhook) => str_starts_with($webhook->name, $this->namePrefix),
        );

        foreach ($this->getWebhooks() as $webhook) {
            $this->client->webhooks()->create(new WebhookRequest(
                $webhook['name'],
                $webhook['endpoint'],
                $webhook['key'],
                $webhook['action'],
                $webhook['resource'],
            ));
        }
    }

    public function getVersion(): string
    {
        $webhooks = iterator_to_array($this->getWebhooks());
        usort($webhooks, static fn (array $a, array $b) => $a['name'] <=> $b['name']);

        $webhooks = array_map(static fn (array $webhook) => $webhook['name'] . $webhook['endpoint'] . $webhook['key'] . $webhook['action'] . $webhook['resource'], $webhooks);

        return md5(implode('', $webhooks));
    }

    /**
     * @return \Generator<array-key, array{name: string, endpoint: string, key: string, action: string, resource: string}>
     */
    private function getWebhooks(): \Generator
    {
        $resources = [
            'Shipments' => ['create', 'cancel'],
            'Orders' => ['create', 'status_update', 'create_fulfillment', 'create_shipment', 'payment_captured', 'payment_voided', 'delete'],
            'Shipment Monitor' => ['latest', 'delivered'],
        ];

        foreach ($resources as $resource => $actions) {
            foreach ($actions as $action) {
                yield [
                    'name' => sprintf('%s - [%s:%s]', $this->namePrefix, u($resource)->snake()->toString(), $action),
                    'endpoint' => $this->urlGenerator->generate(
                        '_webhook_controller',
                        [
                            'type' => 'shipmondo',
                            'resource' => u($resource)->snake()->toString(),
                            'action' => $action,
                        ],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    'key' => $this->webhooksKey,
                    'action' => $action,
                    'resource' => $resource,
                ];
            }
        }
    }
}
