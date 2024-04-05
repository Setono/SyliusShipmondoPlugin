<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Repository;

use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooksInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

/**
 * @extends RepositoryInterface<RegisteredWebhooksInterface>
 */
interface RegisteredWebhooksRepositoryInterface extends RepositoryInterface
{
    public function findOneByHash(string $hash): ?RegisteredWebhooksInterface;
}
