<?php
declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Core\Model\OrderInterface as BaseOrderInterface;

interface OrderInterface extends BaseOrderInterface
{
    public const SHIPMONDO_STATE_PENDING = 'pending';

    public function getShipmondoState(): string;

    public function setShipmondoState(string $shipmondoState): void;
}
