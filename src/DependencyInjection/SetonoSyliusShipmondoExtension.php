<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DependencyInjection;

use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class SetonoSyliusShipmondoExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        /**
         * @psalm-suppress PossiblyNullArgument
         *
         * @var array{api: array{username: string, key: string}, webhooks: array{key: string, name_prefix: string}, resources: array} $config
         */
        $config = $this->processConfiguration($this->getConfiguration([], $container), $configs);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $container
            ->registerForAutoconfiguration(SalesOrderDataMapperInterface::class)
            ->addTag('setono_sylius_shipmondo.sales_order_data_mapper')
        ;

        $container->setParameter('setono_sylius_shipmondo.api.username', $config['api']['username']);
        $container->setParameter('setono_sylius_shipmondo.api.key', $config['api']['key']);
        $container->setParameter('setono_sylius_shipmondo.webhooks.key', $config['webhooks']['key']);
        $container->setParameter('setono_sylius_shipmondo.webhooks.name_prefix', $config['webhooks']['name_prefix']);

        $this->registerResources(
            'setono_sylius_shipmondo',
            SyliusResourceBundle::DRIVER_DOCTRINE_ORM,
            $config['resources'],
            $container,
        );

        $loader->load('services.xml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('framework', [
            'messenger' => [
                'buses' => [
                    'setono_sylius_shipmondo.command_bus' => [
                        'middleware' => [
                            'doctrine_transaction',
                            'router_context',
                        ],
                    ],
                ],
            ],
            'webhook' => [
                'routing' => [
                    'shipmondo' => [
                        'service' => 'setono_sylius_shipmondo.webhook.webhook_parser',
                    ],
                ],
            ],
            'workflows' => OrderWorkflow::getConfig(),
        ]);
    }
}
