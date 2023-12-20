<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\EventSubscriber\Grid;

use Sylius\Component\Grid\Definition\Field;
use Sylius\Component\Grid\Event\GridDefinitionConverterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AddShipmondoStateColumnToOrderGridSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.grid.admin_order' => 'add',
        ];
    }

    public function add(GridDefinitionConverterEvent $event): void
    {
        $field = Field::fromNameAndType('shipmondoState', 'twig');
        $field->setOptions([
            'template' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state.html.twig',
            'vars' => [
                'labels' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/field/shipmondo_state',
            ],
        ]);
        $field->setLabel('setono_sylius_shipmondo.ui.shipmondo_state');

        $event->getGrid()->addField($field);
    }
}
