<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\EventSubscriber;

use Knp\Menu\Integration\Symfony\RoutingExtension;
use Knp\Menu\ItemInterface;
use Knp\Menu\MenuFactory;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\EventSubscriber\AddMenuSubscriber;
use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AddMenuSubscriberTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_subscribes_to_the_admin_main_menu(): void
    {
        self::assertSame(['sylius.menu.admin.main' => 'add'], AddMenuSubscriber::getSubscribedEvents());
    }

    /**
     * @test
     */
    public function it_adds_the_shipmondo_item_to_the_configuration_menu(): void
    {
        $factory = $this->factory();
        $menu = $factory->createItem('root');
        $catalog = $menu->addChild('catalog');
        $configuration = $menu->addChild('configuration');

        (new AddMenuSubscriber())->add(new MenuBuilderEvent($factory, $menu));

        self::assertShipmondoItem($configuration->getChild('shipmondo'));
        self::assertNull($catalog->getChild('shipmondo'));
    }

    /**
     * @test
     */
    public function it_adds_the_shipmondo_item_to_the_first_menu_when_there_is_no_configuration_menu(): void
    {
        $factory = $this->factory();
        $menu = $factory->createItem('root');
        $catalog = $menu->addChild('catalog');
        $sales = $menu->addChild('sales');

        (new AddMenuSubscriber())->add(new MenuBuilderEvent($factory, $menu));

        self::assertShipmondoItem($catalog->getChild('shipmondo'));
        self::assertNull($sales->getChild('shipmondo'));
        self::assertNull($menu->getChild('shipmondo'));
    }

    private function factory(): MenuFactory
    {
        $urlGenerator = $this->prophesize(UrlGeneratorInterface::class);
        $urlGenerator
            ->generate('setono_sylius_shipmondo_admin_shipmondo_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/admin/shipmondo')
        ;

        $factory = new MenuFactory();
        $factory->addExtension(new RoutingExtension($urlGenerator->reveal()));

        return $factory;
    }

    private static function assertShipmondoItem(?ItemInterface $item): void
    {
        self::assertNotNull($item);
        self::assertSame('/admin/shipmondo', $item->getUri());
        self::assertSame('setono_sylius_shipmondo.ui.shipmondo', $item->getLabel());
        self::assertSame('truck', $item->getLabelAttribute('icon'));
    }
}
