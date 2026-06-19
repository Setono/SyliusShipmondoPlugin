<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin;

use Setono\CompositeCompilerPass\CompositeCompilerPass;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Sylius\Bundle\ResourceBundle\AbstractResourceBundle;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class SetonoSyliusShipmondoPlugin extends AbstractResourceBundle
{
    use SyliusPluginTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new CompositeCompilerPass(
            'setono_sylius_shipmondo.data_mapper.sales_order.composite',
            'setono_sylius_shipmondo.sales_order_data_mapper',
        ));

        $container->addCompilerPass(new CompositeCompilerPass(
            'setono_sylius_shipmondo.webhook.remote_event_handler.composite',
            'setono_sylius_shipmondo.remote_event_handler',
        ));
    }

    /**
     * @return list<string>
     */
    public function getSupportedDrivers(): array
    {
        return [
            SyliusResourceBundle::DRIVER_DOCTRINE_ORM,
        ];
    }
}
