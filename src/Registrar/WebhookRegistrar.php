<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Request\Webhooks\Webhook;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function Symfony\Component\String\u;

final class WebhookRegistrar implements WebhookRegistrarInterface
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $webhooksKey,
    ) {
    }

    public function register(): void
    {
        $resources = [
            'Shipments' => ['create', 'cancel'],
            'Orders' => ['create', 'status_update', 'create_fulfillment', 'create_shipment', 'payment_captured', 'payment_voided', 'delete'],
            'Shipment Monitor' => ['latest', 'delivered'],
        ];

        foreach ($resources as $resource => $actions) {
            foreach ($actions as $action) {
                $this->client->webhooks()->create(new Webhook(
                    sprintf('Sylius - [%s:%s]', u($resource)->snake(), $action),
                    $this->urlGenerator->generate(
                        'setono_sylius_shipmondo_global_webhook',
                        ['resource' => u($resource)->snake(), 'action' => $action],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    $this->webhooksKey,
                    $action,
                    $resource,
                ));
            }
        }
    }
}
