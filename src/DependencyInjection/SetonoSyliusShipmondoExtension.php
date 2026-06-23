<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DependencyInjection;

use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\RemoteEventHandlerInterface;
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
         * @var array{api: array{username: string, key: string, sandbox: bool|string}, webhooks: array{key: string, name_prefix: string}, resources: array<string, mixed>} $config
         */
        $config = $this->processConfiguration($this->getConfiguration([], $container), $configs);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $container
            ->registerForAutoconfiguration(SalesOrderDataMapperInterface::class)
            ->addTag('setono_sylius_shipmondo.sales_order_data_mapper')
        ;

        $container
            ->registerForAutoconfiguration(RemoteEventHandlerInterface::class)
            ->addTag('setono_sylius_shipmondo.remote_event_handler')
        ;

        $container->setParameter('setono_sylius_shipmondo.api.username', $config['api']['username']);
        $container->setParameter('setono_sylius_shipmondo.api.key', $config['api']['key']);
        $container->setParameter('setono_sylius_shipmondo.api.sandbox', $config['api']['sandbox']);
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

        // Hook order cancellation so the order's Shipmondo sales order is deleted. Sylius 1.14 runs
        // `sylius_order` on winzou by default (this callback); the Symfony Workflow path is handled by
        // the event listener tagged in event_listener.xml, for when `sylius_order` uses that adapter.
        if ($container->hasExtension('winzou_state_machine')) {
            $container->prependExtensionConfig('winzou_state_machine', [
                'sylius_order' => [
                    'callbacks' => [
                        'after' => [
                            'setono_sylius_shipmondo_delete_sales_order' => [
                                'on' => ['cancel'],
                                'do' => ['@setono_sylius_shipmondo.event_listener.order_cancellation', '__invoke'],
                                'args' => ['object'],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        $container->prependExtensionConfig('sylius_ui', [
            'events' => [
                'sylius.admin.shipping_method.update.javascripts' => [
                    'blocks' => [
                        'javascripts' => [
                            'template' => '@SetonoSyliusShipmondoPlugin/admin/shipping_method/form/_javascripts.html.twig',
                        ],
                    ],
                ],
                'sylius.admin.shipping_method.create.javascripts' => [
                    'blocks' => [
                        'javascripts' => [
                            'template' => '@SetonoSyliusShipmondoPlugin/admin/shipping_method/form/_javascripts.html.twig',
                        ],
                    ],
                ],
                'sylius.shop.layout.javascripts' => [
                    'blocks' => [
                        'javascripts' => [
                            'template' => '@SetonoSyliusShipmondoPlugin/shop/_javascripts.html.twig',
                        ],
                    ],
                ],
            ],
        ]);
    }
}
