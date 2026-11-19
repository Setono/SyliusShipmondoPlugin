<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Exception\ShipmondoException;

interface WebhookRegistrarInterface
{
    /**
     * Registers the shop's webhooks with Shipmondo, and then records the registration, which the admin's Shipmondo page
     * finds by the version
     *
     * @throws UnreachableWebhookEndpointException when Shipmondo can't reach the webhooks' endpoint, before anything
     *                                             is registered
     * @throws ShipmondoException when a call to Shipmondo fails, which can leave only some of the webhooks registered.
     *                            The registration isn't recorded
     */
    public function register(): void;

    /**
     * Will return a list of webhooks that this registrar will register
     *
     * @return iterable<Webhook>
     */
    public function getWebhooks(): iterable;

    /**
     * Should return a version (string) that uniquely identifies the webhooks being registered
     */
    public function getVersion(): string;
}
