<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class Extension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sss_shipping_methods_with_pickup_point_delivery', [Runtime::class, 'getShippingMethodsWithPickupPointDelivery']),
        ];
    }
}
