<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Core\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Resource\Model\VersionedInterface;

interface OrderInterface extends BaseOrderInterface, VersionedInterface
{
    public const SHIPMONDO_STATE_PENDING = 'pending';

    public const SHIPMONDO_STATE_DISPATCHING = 'dispatching';

    public function getShipmondoState(): string;

    public function setShipmondoState(string $shipmondoState): void;
}
