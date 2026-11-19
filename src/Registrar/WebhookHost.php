<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

/**
 * Which hosts Shipmondo can send the webhooks to: the one rule for the registrar, which registers no webhooks at a host
 * Shipmondo can't reach, and for the register webhooks command, which chooses the host
 *
 * @internal
 */
final class WebhookHost
{
    /**
     * Whether Shipmondo can reach the host: it is a hostname, and it doesn't name the shop's own machine, as localhost,
     * a name under localhost and a loopback address (127.0.0.0/8) do, also when fully qualified (with a trailing dot)
     */
    public static function isReachable(string $host): bool
    {
        $name = rtrim(strtolower($host), '.');

        return false !== filter_var($host, \FILTER_VALIDATE_DOMAIN, \FILTER_FLAG_HOSTNAME) &&
            'localhost' !== $name && !str_ends_with($name, '.localhost') &&
            !(false !== filter_var($name, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4) && str_starts_with($name, '127.'));
    }
}
