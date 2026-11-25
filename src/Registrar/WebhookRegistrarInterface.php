<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

use Setono\Shipmondo\Exception\ShipmondoException;

interface WebhookRegistrarInterface
{
    /**
     * Makes this shop's webhooks in Shipmondo match the wanted ones at the endpoint: it creates the missing ones,
     * including those Shipmondo has deactivated or sends to another endpoint, deletes the ones that aren't wanted and the
     * duplicates, and leaves the rest. When nothing differs, it only fetches the webhooks. Then it records the
     * registration: the endpoint, and the time
     *
     * @param string|null $endpoint the URL Shipmondo sends the webhooks to, e.g. https://shop.example.com/webhook/shipmondo,
     *                              such as the latest registration's. Null for the shop's webhook endpoint as the router
     *                              generates it, e.g. at the host the admin is opened at
     *
     * @throws UnreachableWebhookEndpointException when Shipmondo can't reach the endpoint, before anything is asked of
     *                                             Shipmondo
     * @throws ShipmondoException when a call to Shipmondo fails, which can leave only some of the differences fixed.
     *                            The registration isn't recorded
     */
    public function register(?string $endpoint = null): void;

    /**
     * Compares this shop's webhooks in Shipmondo with the wanted ones at the endpoint, without changing anything
     *
     * @param string $endpoint the URL Shipmondo sends the webhooks to, e.g. https://shop.example.com/webhook/shipmondo
     *
     * @throws ShipmondoException when the webhooks can't be fetched from Shipmondo
     */
    public function compare(string $endpoint): WebhookComparison;
}
