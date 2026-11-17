<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin;

use Setono\CompositeCompilerPass\CompositeCompilerPass;
use Setono\SyliusShipmondoPlugin\DataMapper\CompositeSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\DependencyInjection\Compiler\RegisterOrderWebhookHandlersPass;
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
            CompositeSalesOrderDataMapper::class,
            'setono_sylius_shipmondo.sales_order_data_mapper',
        ));

        $container->addCompilerPass(new RegisterOrderWebhookHandlersPass());
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
