<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DependencyInjection;

use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Doctrine\Type\PickupPointType;
use Setono\SyliusShipmondoPlugin\Doctrine\Type\RecordedShipmondoFailureType;
use Setono\SyliusShipmondoPlugin\EventListener\OrderCancellationListener;
use Setono\SyliusShipmondoPlugin\EventSubscriber\Grid\AddShipmondoToOrderGridSubscriber;
use Setono\SyliusShipmondoPlugin\Message\Middleware\AbsorbDeleteOrderFailureMiddleware;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlerInterface;
use Setono\SyliusShipmondoPlugin\Webhook\WebhookParser;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Bundle\ResourceBundle\DependencyInjection\Extension\AbstractResourceExtension;
use Sylius\Bundle\ResourceBundle\SyliusResourceBundle;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;

final class SetonoSyliusShipmondoExtension extends AbstractResourceExtension implements PrependExtensionInterface
{
    /**
     * The framework scoped HTTP client every call to Shipmondo goes through, except the shop's pickup point search.
     * FrameworkBundle registers its PSR-18 version as "psr18.<name>"
     */
    public const HTTP_CLIENT = 'setono_sylius_shipmondo.http_client';

    /**
     * The framework scoped HTTP client of the shop's pickup point search, which a customer waits for in checkout
     */
    public const PICKUP_POINTS_HTTP_CLIENT = 'setono_sylius_shipmondo.pickup_points_http_client';

    /**
     * The Shipmondo API, in production and in the sandbox
     */
    private const SHIPMONDO_SCOPE = 'https://(app|sandbox)\.shipmondo\.com/';

    public function load(array $configs, ContainerBuilder $container): void
    {
        /**
         * @var array{order_id_prefix: string, api: array{username: string, key: string, sandbox: bool|string}, webhooks: array{key: string, name_prefix: string, register_automatically: bool}, resources: array<string, mixed>} $config
         */
        $config = $this->processConfiguration($this->getConfiguration([], $container), $configs);
        $loader = new XmlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));

        $container
            ->registerForAutoconfiguration(SalesOrderDataMapperInterface::class)
            ->addTag('setono_sylius_shipmondo.sales_order_data_mapper')
        ;

        $container
            ->registerForAutoconfiguration(OrderWebhookHandlerInterface::class)
            ->addTag('setono_sylius_shipmondo.order_webhook_handler')
        ;

        $container->setParameter('setono_sylius_shipmondo.api.username', $config['api']['username']);
        $container->setParameter('setono_sylius_shipmondo.api.key', $config['api']['key']);
        $container->setParameter('setono_sylius_shipmondo.api.sandbox', $config['api']['sandbox']);
        $container->setParameter('setono_sylius_shipmondo.webhooks.key', $config['webhooks']['key']);
        $container->setParameter('setono_sylius_shipmondo.webhooks.name_prefix', $config['webhooks']['name_prefix']);
        $container->setParameter('setono_sylius_shipmondo.webhooks.register_automatically', $config['webhooks']['register_automatically']);
        $container->setParameter('setono_sylius_shipmondo.order_id_prefix', $config['order_id_prefix']);

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
                            // inside doctrine_transaction, so a sales order deletion it absorbs never rolls a transaction back
                            AbsorbDeleteOrderFailureMiddleware::class,
                        ],
                    ],
                ],
            ],
            'webhook' => [
                'routing' => [
                    'shipmondo' => [
                        'service' => WebhookParser::class,
                    ],
                ],
            ],
            'workflows' => OrderWorkflow::getConfig(),
            // A shop changes a limit in its own framework config, naming only what it changes: the rest stays.
            // retry_failed stays off: the plugin retries on its own terms, and Symfony's retry repeats even a POST,
            // which can create a sales order twice
            'http_client' => [
                'scoped_clients' => [
                    self::HTTP_CLIENT => [
                        'scope' => self::SHIPMONDO_SCOPE,
                        'timeout' => 10, // the longest wait with no data at all
                        'max_duration' => 30, // the most one request may take
                        'retry_failed' => false,
                    ],
                    self::PICKUP_POINTS_HTTP_CLIENT => [
                        'scope' => self::SHIPMONDO_SCOPE,
                        'timeout' => 5,
                        'max_duration' => 10,
                        'retry_failed' => false,
                    ],
                ],
            ],
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
                                'do' => ['@' . OrderCancellationListener::class, '__invoke'],
                                'args' => ['object'],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        // Store the pickup point a customer chose on the shipment (ShipmentTrait), and the Shipmondo failure that put an
        // order in its Shipmondo state on the order (OrderTrait)
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'dbal' => [
                    'types' => [
                        PickupPointType::NAME => PickupPointType::class,
                        RecordedShipmondoFailureType::NAME => RecordedShipmondoFailureType::class,
                    ],
                ],
            ]);
        }

        // The template of the order grid's bulk action that retries the upload of the selected orders
        $container->prependExtensionConfig('sylius_grid', [
            'templates' => [
                'bulk_action' => [
                    AddShipmondoToOrderGridSubscriber::RETRY_UPLOAD_BULK_ACTION => '@SetonoSyliusShipmondoPlugin/admin/order/grid/bulk_action/retry_upload.html.twig',
                ],
            ],
        ]);

        $container->prependExtensionConfig('sylius_ui', [
            'events' => [
                // right after the order's shipments
                'sylius.admin.order.show.sidebar' => [
                    'blocks' => [
                        'setono_sylius_shipmondo' => [
                            'template' => '@SetonoSyliusShipmondoPlugin/admin/order/show/_shipmondo.html.twig',
                            'priority' => 32,
                        ],
                    ],
                ],
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
