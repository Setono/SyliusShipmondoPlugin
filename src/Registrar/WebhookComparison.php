<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Response\Webhook\Webhook as WebhookResponse;

/**
 * How this shop's webhooks in Shipmondo differ from the wanted ones at an endpoint: one per action a webhook handler
 * handles on the orders resource, active and sent to the endpoint. Registering creates the missing ones and deletes the
 * unwanted ones, and leaves the rest
 */
final readonly class WebhookComparison
{
    /**
     * @param list<Webhook> $missing the wanted webhooks Shipmondo doesn't have, active at the endpoint
     * @param list<WebhookResponse> $unwanted this shop's webhooks in Shipmondo that aren't wanted: for an action no
     *                                        webhook handler handles, inactive, sent to another endpoint, or a duplicate
     */
    public function __construct(
        public array $missing,
        public array $unwanted,
    ) {
    }

    /**
     * Whether this shop's webhooks in Shipmondo are exactly the wanted ones
     */
    public function matches(): bool
    {
        return [] === $this->missing && [] === $this->unwanted;
    }
}
