<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\Shipmondo\Request\Webhook\WebhookRequest;
use Setono\Shipmondo\Response\Webhook\Webhook as WebhookResponse;
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
                $webhook->name,
                $webhook->endpoint,
                $webhook->key,
                $webhook->action,
                $webhook->resource,
            ));
        }
    }

    public function getVersion(): string
    {
        $webhooks = iterator_to_array($this->getWebhooks());
        usort($webhooks, static fn (Webhook $a, Webhook $b) => $a->name <=> $b->name);

        $webhooks = array_map(static fn (Webhook $webhook) => $webhook->name . $webhook->endpoint . $webhook->key . $webhook->action->value . $webhook->resource->value, $webhooks);

        return md5(implode('', $webhooks));
    }

    /**
     * @return \Generator<array-key, Webhook>
     */
    public function getWebhooks(): \Generator
    {
        $resources = [
            [WebhookResourceName::Shipments, [WebhookAction::Create, WebhookAction::Cancel]],
            [WebhookResourceName::Orders, [WebhookAction::Create, WebhookAction::StatusUpdate, WebhookAction::CreateFulfillment, WebhookAction::CreateShipment, WebhookAction::PaymentCaptured, WebhookAction::PaymentVoided, WebhookAction::Delete]],
            [WebhookResourceName::ShipmentMonitor, [WebhookAction::Latest, WebhookAction::Delivered]],
        ];

        foreach ($resources as [$resource, $actions]) {
            foreach ($actions as $action) {
                yield new Webhook(
                    sprintf('%s - [%s:%s]', $this->namePrefix, u($resource->value)->snake()->toString(), $action->value),
                    $this->urlGenerator->generate(
                        '_webhook_controller',
                        ['type' => 'shipmondo'],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    $this->webhooksKey,
                    $action,
                    $resource,
                );
            }
        }
    }
}
