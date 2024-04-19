<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Application\Model;

use Doctrine\ORM\Mapping as ORM;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface as ShipmondoShippingMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodTrait as ShipmondoShippingMethodTrait;
use Sylius\Component\Core\Model\ShippingMethod as BaseShippingMethod;

/**
 * @ORM\Entity
 *
 * @ORM\Table(name="sylius_shipping_method")
 */
class ShippingMethod extends BaseShippingMethod implements ShipmondoShippingMethodInterface
{
    use ShipmondoShippingMethodTrait;
}
