<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Registrar;

/**
 * The webhooks were not registered again, because no registration with an endpoint is recorded: they have never been
 * registered, or not since the plugin recorded where. Nothing was asked of Shipmondo. An admin registers them once,
 * which records the endpoint the upload run registers them again at
 */
final class NoRecordedEndpointException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The webhooks were not registered again: no registration with an endpoint is recorded. Register them once from the admin\'s Shipmondo page or with setono:sylius-shipmondo:register-webhooks.');
    }
}
