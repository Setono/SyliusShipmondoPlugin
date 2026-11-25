<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\Shipmondo\Request\Webhook\WebhookRequest;
use Setono\Shipmondo\Response\Webhook\Webhook as WebhookResponse;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooksInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use function Symfony\Component\String\u;
use Webmozart\Assert\Assert;

/**
 * Registers the webhooks the webhook handlers handle: one per action on the orders resource that a handler subscribes
 * to. It records each registration, which the admin's Shipmondo page reads to say when the webhooks were registered
 */
final readonly class WebhookRegistrar implements WebhookRegistrarInterface
{
    public function __construct(
        private ClientInterface $client,
        private UrlGeneratorInterface $urlGenerator,
        private OrderWebhookHandlers $handlers,
        private RegisteredWebhooksRepositoryInterface $registeredWebhooksRepository,
        private FactoryInterface $registeredWebhooksFactory,
        private string $webhooksKey,
        private string $namePrefix,
    ) {
    }

    /**
     * Replaces this shop's webhooks only: other shops on the same Shipmondo account register theirs under the same name
     * prefix. A webhook is this shop's when it carries the shop's id, e.g. one registered before the shop changed its
     * address, or when it is sent to the shop's endpoint, e.g. one registered before webhooks carried the shop's id.
     *
     * Registers nothing, and so keeps the webhooks registered before, when Shipmondo can't reach the endpoint, e.g.
     * when the admin is opened at localhost
     *
     * Records the registration once Shipmondo has registered every webhook
     */
    public function register(): void
    {
        $endpoint = $this->endpoint();
        if (!WebhookHost::isReachable((string) parse_url($endpoint, \PHP_URL_HOST))) {
            throw new UnreachableWebhookEndpointException($endpoint);
        }

        $namePrefixWithShopId = $this->namePrefixWithShopId();

        $this->client->webhooks()->deleteAll(
            fn (WebhookResponse $webhook) => str_starts_with($webhook->name, $namePrefixWithShopId) ||
                (str_starts_with($webhook->name, $this->namePrefix) && $webhook->endpoint === $endpoint),
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

        $this->recordRegistration();
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
        $resource = WebhookResourceName::Orders;

        foreach ($this->handlers->subscribedActions() as $action) {
            yield new Webhook(
                sprintf('%s - [%s:%s]', $this->namePrefixWithShopId(), u($resource->value)->snake()->toString(), $action->value),
                $this->endpoint(),
                $this->webhooksKey,
                $action,
                $resource,
            );
        }
    }

    /**
     * Records that the current webhooks are registered, and when. It runs once Shipmondo has answered every call, so a
     * registration that failed part of the way isn't recorded
     */
    private function recordRegistration(): void
    {
        $version = $this->getVersion();

        $registeredWebhooks = $this->registeredWebhooksRepository->findOneByVersion($version);
        if (null === $registeredWebhooks) {
            $registeredWebhooks = $this->registeredWebhooksFactory->createNew();
            Assert::isInstanceOf($registeredWebhooks, RegisteredWebhooksInterface::class);

            $registeredWebhooks->setVersion($version);
        }

        $registeredWebhooks->setRegisteredAt(new \DateTimeImmutable());

        $this->registeredWebhooksRepository->add($registeredWebhooks);
    }

    /**
     * The name prefix and this shop's id, e.g. "Setono Sylius Shipmondo Plugin [3fd02dbf]". The id tells the shop apart
     * from the other shops on its Shipmondo account: the first 8 hexadecimal characters of an HMAC with the shop's
     * webhooks key, so it stays the same when the shop's address changes, and it gives nothing of the key away
     */
    private function namePrefixWithShopId(): string
    {
        return sprintf('%s [%s]', $this->namePrefix, substr(hash_hmac('sha256', 'shop id', $this->webhooksKey), 0, 8));
    }

    private function endpoint(): string
    {
        return $this->urlGenerator->generate('_webhook_controller', ['type' => 'shipmondo'], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
