<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\EventSubscriber\Grid;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\EventSubscriber\Grid\AddPickupPointDeliveryToShippingMethodGridSubscriber;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Event\GridDefinitionConverterEvent;

final class AddPickupPointDeliveryToShippingMethodGridSubscriberTest extends TestCase
{
    /**
     * @test
     */
    public function it_subscribes_to_the_admin_shipping_method_grid(): void
    {
        self::assertSame(
            ['sylius.grid.admin_shipping_method' => 'add'],
            AddPickupPointDeliveryToShippingMethodGridSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * @test
     */
    public function it_adds_a_pickup_point_delivery_column(): void
    {
        $grid = Grid::fromCodeAndDriverConfiguration('sylius_admin_shipping_method', 'doctrine/orm', []);

        (new AddPickupPointDeliveryToShippingMethodGridSubscriber())->add(new GridDefinitionConverterEvent($grid));

        $field = $grid->getField('pickupPointDelivery');
        self::assertSame('twig', $field->getType());
        self::assertSame('.', $field->getPath());
        self::assertSame('setono_sylius_shipmondo.ui.pickup_point_delivery', $field->getLabel());
        self::assertSame([
            'template' => '@SetonoSyliusShipmondoPlugin/admin/shipping_method/grid/field/pickup_point_delivery.html.twig',
        ], $field->getOptions());
    }
}
