<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Repository;

use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooksInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;

interface RegisteredWebhooksRepositoryInterface extends RepositoryInterface
{
    /**
     * The registration of the webhooks that was recorded last, or null when none is
     */
    public function findLatest(): ?RegisteredWebhooksInterface;
}
