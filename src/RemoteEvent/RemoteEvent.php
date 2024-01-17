<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\RemoteEvent;

use Symfony\Component\RemoteEvent\RemoteEvent as BaseRemoteEvent;

/**
 * The Symfony remote event only allows the payload array, but we need the resource and action from the request query
 */
final class RemoteEvent extends BaseRemoteEvent
{
    public function __construct(
        string $name,
        array $payload,
        private readonly string $resource,
        private readonly string $action,
    ) {
        // Shipmondo doesn't send a unique id, so we generate one for no apparent reason probably
        parent::__construct($name, bin2hex(random_bytes(8)), $payload);
    }

    public function getResource(): string
    {
        return $this->resource;
    }

    public function getAction(): string
    {
        return $this->action;
    }
}
