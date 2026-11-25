<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Psr\Log\LoggerInterface;
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
 * Keeps this shop's webhooks in Shipmondo matching the wanted ones: one per action on the orders resource that a
 * webhook handler subscribes to, active and sent to the endpoint. It records each registration, whose endpoint the
 * upload run registers the webhooks again at, and which the admin's Shipmondo page shows
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
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Creates the missing webhooks before it deletes the unwanted ones: Shipmondo calls a webhook's endpoint when it is
     * created, so a webhook it refuses to create leaves the webhooks it replaces working
     */
    public function register(): void
    {
        $endpoint = $this->endpoint();
        if (!WebhookHost::isReachable((string) parse_url($endpoint, \PHP_URL_HOST))) {
            throw new UnreachableWebhookEndpointException($endpoint);
        }

        $comparison = $this->compare($endpoint);

        foreach ($comparison->missing as $webhook) {
            $this->client->webhooks()->create(new WebhookRequest(
                $webhook->name,
                $webhook->endpoint,
                $webhook->key,
                $webhook->action,
                $webhook->resource,
            ));
        }

        foreach ($comparison->unwanted as $webhook) {
            $this->client->webhooks()->delete($webhook->id);
        }

        $this->recordRegistration($endpoint);

        if (!$comparison->matches()) {
            $this->logger->info('This shop\'s webhooks in Shipmondo did not match the wanted ones at {endpoint}, so the missing ones were created and the unwanted ones deleted', [
                'endpoint' => $endpoint,
                'created' => array_map(static fn (Webhook $webhook): string => $webhook->name, $comparison->missing),
                'deleted' => array_map(static fn (WebhookResponse $webhook): string => sprintf('%s (%d)', $webhook->name, $webhook->id), $comparison->unwanted),
            ]);
        }
    }

    /**
     * A webhook is this shop's when it carries the shop's id, e.g. one registered before the shop changed its address,
     * or when it has the name prefix and is sent to the endpoint, e.g. one registered before webhooks carried the shop's
     * id. Other shops on the same Shipmondo account register theirs under the same name prefix, and keep them
     */
    public function compare(string $endpoint): WebhookComparison
    {
        $namePrefixWithShopId = $this->namePrefixWithShopId();

        $missing = $this->wantedWebhooks($endpoint);
        $unwanted = [];

        foreach ($this->client->webhooks()->paginate() as $webhook) {
            if (!str_starts_with($webhook->name, $namePrefixWithShopId) &&
                !(str_starts_with($webhook->name, $this->namePrefix) && $webhook->endpoint === $endpoint)
            ) {
                continue;
            }

            foreach ($missing as $key => $wanted) {
                if ($wanted->isListedAs($webhook)) {
                    unset($missing[$key]);

                    continue 2;
                }
            }

            // it isn't wanted, or another webhook already is the wanted one
            $unwanted[] = $webhook;
        }

        return new WebhookComparison(array_values($missing), $unwanted);
    }

    /**
     * @return list<Webhook>
     */
    private function wantedWebhooks(string $endpoint): array
    {
        $resource = WebhookResourceName::Orders;

        $webhooks = [];
        foreach ($this->handlers->subscribedActions() as $action) {
            $webhooks[] = new Webhook(
                sprintf('%s - [%s:%s]', $this->namePrefixWithShopId(), u($resource->value)->snake()->toString(), $action->value),
                $endpoint,
                $this->webhooksKey,
                $action,
                $resource,
            );
        }

        return $webhooks;
    }

    /**
     * Records the endpoint the webhooks are registered at, and when, on the latest registration. It runs once Shipmondo
     * has answered every call, so a registration that failed part of the way isn't recorded
     */
    private function recordRegistration(string $endpoint): void
    {
        $registeredWebhooks = $this->registeredWebhooksRepository->findLatest();
        if (null === $registeredWebhooks) {
            $registeredWebhooks = $this->registeredWebhooksFactory->createNew();
            Assert::isInstanceOf($registeredWebhooks, RegisteredWebhooksInterface::class);
        }

        $registeredWebhooks->setEndpoint($endpoint);
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
