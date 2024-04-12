<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Core\Model\PaymentMethodInterface as BasePaymentMethodInterface;

interface PaymentMethodInterface extends BasePaymentMethodInterface
{
    /**
     * The id of this payment method in Shipmondo (if it exists)
     */
    public function getShipmondoId(): ?int;

    public function setShipmondoId(?int $shipmondoId): void;
}
