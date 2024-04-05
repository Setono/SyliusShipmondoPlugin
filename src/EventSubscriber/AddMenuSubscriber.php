<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\EventSubscriber;

use Knp\Menu\ItemInterface;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class AddMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'sylius.menu.admin.main' => 'add',
        ];
    }

    public function add(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $subMenu = $menu->getChild('configuration');

        if (null !== $subMenu) {
            $this->addChild($subMenu);
        } else {
            $this->addChild($menu->getFirstChild());
        }
    }

    private function addChild(ItemInterface $item): void
    {
        $item
            ->addChild('shipmondo', [
                'route' => 'setono_sylius_shipmondo_admin_shipmondo_index',
            ])
            ->setLabel('setono_sylius_shipmondo.ui.shipmondo')
            ->setLabelAttribute('icon', 'truck')
        ;
    }
}
