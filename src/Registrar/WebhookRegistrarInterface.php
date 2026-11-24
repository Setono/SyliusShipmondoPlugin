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
     * @param string|null $endpoint where Shipmondo sends the webhooks, or null for the shop's webhook endpoint as the
     *                              router generates it, e.g. at the host the admin is opened at
     *
     * @throws UnreachableWebhookEndpointException when Shipmondo can't reach the endpoint, before anything is asked of
     *                                             Shipmondo
     * @throws ShipmondoException when a call to Shipmondo fails, which can leave only some of the differences fixed.
     *                            The registration isn't recorded
     */
    public function register(?string $endpoint = null): void;

    /**
     * Registers the webhooks again, as {@see self::register()} does, at the endpoint of the latest registration, which
     * an admin's registration sets. The upload run calls it, so a change to the actions the webhook handlers handle, or
     * to this shop's webhooks in Shipmondo, is fixed without a manual step
     *
     * @throws NoRecordedEndpointException when no registration with an endpoint is recorded, before anything is asked
     *                                     of Shipmondo
     * @throws UnreachableWebhookEndpointException when Shipmondo can't reach the recorded endpoint, before anything is
     *                                             asked of Shipmondo
     * @throws ShipmondoException when a call to Shipmondo fails. The registration isn't recorded
     */
    public function registerAgain(): void;

    /**
     * Compares this shop's webhooks in Shipmondo with the wanted ones at the endpoint, without changing anything
     *
     * @throws ShipmondoException when the webhooks can't be fetched from Shipmondo
     */
    public function compare(string $endpoint): WebhookComparison;
}
