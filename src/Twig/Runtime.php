<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Twig;

use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Twig\Extension\RuntimeExtensionInterface;

final class Runtime implements RuntimeExtensionInterface
{
    public function __construct(private readonly ShippingMethodRepositoryInterface $shippingMethodRepository)
    {
    }

    /**
     * @return list<string>
     */
    public function getShippingMethodsWithPickupPointDelivery(): array
    {
        /** @var list<ShippingMethodInterface> $shippingMethods */
        $shippingMethods = $this->shippingMethodRepository->findBy([
            'enabled' => true,
            'pickupPointDelivery' => true,
        ]);

        return array_map(
            static fn (ShippingMethodInterface $shippingMethod) => (string) $shippingMethod->getCode(),
            $shippingMethods,
        );
    }
}
