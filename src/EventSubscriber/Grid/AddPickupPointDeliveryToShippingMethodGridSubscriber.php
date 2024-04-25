<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\EventSubscriber\Grid;

use Sylius\Component\Grid\Definition\Field;
use Sylius\Component\Grid\Event\GridDefinitionConverterEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AddPickupPointDeliveryToShippingMethodGridSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.grid.admin_shipping_method' => 'add',
        ];
    }

    public function add(GridDefinitionConverterEvent $event): void
    {
        $field = Field::fromNameAndType('pickupPointDelivery', 'twig');
        $field->setPath('.');
        $field->setOptions([
            'template' => '@SetonoSyliusShipmondoPlugin/admin/shipping_method/grid/field/pickup_point_delivery.html.twig',
        ]);
        $field->setLabel('setono_sylius_shipmondo.ui.pickup_point_delivery');

        $event->getGrid()->addField($field);
    }
}
