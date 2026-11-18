<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

/**
 * RegisterWebhooksCommand has no hostname to register the webhooks at: it found none Shipmondo can reach, or it found
 * several and can't ask which one. Its message says why, for whoever runs the command
 *
 * @internal
 */
final class NoWebhookHostnameException extends \RuntimeException
{
    public static function unreachable(string $hostname): self
    {
        return new self(sprintf('Shipmondo can\'t reach the hostname "%s". Pass the shop\'s hostname, e.g. --hostname=shop.example.com.', $hostname));
    }

    /**
     * @param list<string> $hostnames
     */
    public static function severalChannelHostnames(array $hostnames): self
    {
        return new self(sprintf(
            'The channels have several hostnames: %s. Choose the one Shipmondo sends the webhooks to with --hostname.',
            implode(', ', $hostnames),
        ));
    }

    public static function noneReachable(string $defaultUriHost): self
    {
        return new self(sprintf(
            'Found no hostname Shipmondo can reach to send the webhooks to: no channel has one, and the router\'s default URI (framework.router.default_uri) has %s. Give a channel the shop\'s hostname, or pass it with --hostname.',
            $defaultUriHost,
        ));
    }
}
