<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\EventSubscriber\Grid;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\EventSubscriber\Grid\AddShipmondoStateColumnToOrderGridSubscriber;
use Sylius\Component\Grid\Definition\Grid;
use Sylius\Component\Grid\Event\GridDefinitionConverterEvent;

final class AddShipmondoStateColumnToOrderGridSubscriberTest extends TestCase
{
    /**
     * @test
     */
    public function it_subscribes_to_the_admin_order_grid(): void
    {
        self::assertSame(
            ['sylius.grid.admin_order' => 'add'],
            AddShipmondoStateColumnToOrderGridSubscriber::getSubscribedEvents(),
        );
    }

    /**
     * @test
     */
    public function it_adds_a_shipmondo_state_column(): void
    {
        $grid = Grid::fromCodeAndDriverConfiguration('sylius_admin_order', 'doctrine/orm', []);

        (new AddShipmondoStateColumnToOrderGridSubscriber())->add(new GridDefinitionConverterEvent($grid));

        $field = $grid->getField('shipmondoState');
        self::assertSame('twig', $field->getType());
        self::assertSame('shipmondoState', $field->getPath());
        self::assertSame('setono_sylius_shipmondo.ui.shipmondo_state', $field->getLabel());
        self::assertSame([
            'template' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state.html.twig',
            'vars' => [
                'labels' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state',
            ],
        ], $field->getOptions());
    }
}
