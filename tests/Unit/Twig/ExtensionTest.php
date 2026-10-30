<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Twig\Extension;
use Setono\SyliusShipmondoPlugin\Twig\Runtime;

final class ExtensionTest extends TestCase
{
    /**
     * @test
     */
    public function it_exposes_the_pickup_point_delivery_shipping_methods_function_lazily_via_the_runtime(): void
    {
        $functions = (new Extension())->getFunctions();

        self::assertCount(1, $functions);
        self::assertSame('sss_shipping_methods_with_pickup_point_delivery', $functions[0]->getName());
        self::assertSame([Runtime::class, 'getShippingMethodsWithPickupPointDelivery'], $functions[0]->getCallable());
    }
}
