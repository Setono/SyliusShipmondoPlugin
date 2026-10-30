<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Twig\Runtime;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;

final class RuntimeTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_returns_the_codes_of_the_enabled_shipping_methods_with_pickup_point_delivery(): void
    {
        $repository = $this->prophesize(ShippingMethodRepositoryInterface::class);
        $repository->findBy(['enabled' => true, 'pickupPointDelivery' => true])->willReturn([
            self::shippingMethod('gls_pickup'),
            self::shippingMethod(null),
            self::shippingMethod('post_nord_pickup'),
        ]);

        self::assertSame(
            ['gls_pickup', '', 'post_nord_pickup'],
            (new Runtime($repository->reveal()))->getShippingMethodsWithPickupPointDelivery(),
        );
    }

    private static function shippingMethod(?string $code): ShippingMethod
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setCode($code);

        return $shippingMethod;
    }
}
