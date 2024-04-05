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

        $resources = [
            'Shipments' => ['create', 'cancel'],
            'Orders' => ['create', 'status_update', 'create_fulfillment', 'create_shipment', 'payment_captured', 'payment_voided', 'delete'],
            'Shipment Monitor' => ['latest', 'delivered'],
        ];

        foreach ($resources as $resource => $actions) {
            foreach ($actions as $action) {
                $this->client->webhooks()->create(new WebhookRequest(
                    sprintf('%s - [%s:%s]', $this->namePrefix, u($resource)->snake()->toString(), $action),
                    $this->urlGenerator->generate(
                        '_webhook_controller',
                        [
                            'type' => 'shipmondo',
                            'resource' => u($resource)->snake()->toString(),
                            'action' => $action,
                        ],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    $this->webhooksKey,
                    $action,
                    $resource,
                ));
            }
        }
    }

    public function getHash(): string
    {
        return md5_file(__FILE__);
    }
}
