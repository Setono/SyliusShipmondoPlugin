<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Setono\Shipmondo\Client\Client;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCanceller;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\Controller\Shop\GetPickupPointsAction;
use Setono\SyliusShipmondoPlugin\DataMapper\CompositeSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\DependencyInjection\SetonoSyliusShipmondoExtension;
use Setono\SyliusShipmondoPlugin\Doctrine\Type\PickupPointType;
use Setono\SyliusShipmondoPlugin\EventListener\OrderCancellationListener;
use Setono\SyliusShipmondoPlugin\HttpClient\ShipmondoHttpClient;
use Setono\SyliusShipmondoPlugin\Message\Middleware\AbsorbDeleteOrderFailureMiddleware;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProvider;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Provider\SetupProvider;
use Setono\SyliusShipmondoPlugin\Provider\SetupProviderInterface;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrar;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploader;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlerInterface;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolver;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Setono\SyliusShipmondoPlugin\Webhook\WebhookParser;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

/**
 * See examples of tests and configuration options here: https://github.com/SymfonyTest/SymfonyDependencyInjectionTest
 */
final class SetonoSyliusShipmondoExtensionTest extends AbstractExtensionTestCase
{
    protected function getContainerExtensions(): array
    {
        return [
            new SetonoSyliusShipmondoExtension(),
        ];
    }

    /**
     * @test
     */
    public function after_loading_the_correct_parameter_has_been_set(): void
    {
        $this->load();

        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.api.username', '%env(SHIPMONDO_USERNAME)%');
        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.api.key', '%env(SHIPMONDO_KEY)%');
        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.api.sandbox', '%env(bool:SHIPMONDO_SANDBOX)%');
        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.webhooks.key', '%env(SHIPMONDO_WEBHOOKS_KEY)%');
        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.webhooks.name_prefix', 'Setono Sylius Shipmondo Plugin');
    }

    /**
     * @test
     */
    public function it_registers_the_resources_and_loads_the_services(): void
    {
        $this->load();

        $this->assertContainerBuilderHasParameter('setono_sylius_shipmondo.model.registered_webhooks.class', RegisteredWebhooks::class);
        $this->assertContainerBuilderHasService(WebhookParser::class);
    }

    /**
     * @test
     */
    public function it_names_every_service_it_defines_after_its_class(): void
    {
        $this->load();

        // gives a service without a class the class its id names, as when the container is compiled
        (new ResolveClassPass())->process($this->container);

        // the services Sylius registers for the plugin's resource follow Sylius's naming
        $resourceServices = [
            'setono_sylius_shipmondo.controller.registered_webhooks',
            'setono_sylius_shipmondo.factory.registered_webhooks',
            'setono_sylius_shipmondo.repository.registered_webhooks',
        ];

        $misnamed = [];
        foreach ($this->container->getDefinitions() as $id => $definition) {
            // the container itself, and anonymous services, which have no id of their own
            if ($definition->isSynthetic() || str_starts_with($id, '.') || in_array($id, $resourceServices, true)) {
                continue;
            }

            if ($id !== $definition->getClass()) {
                $misnamed[$id] = $definition->getClass();
            }
        }

        self::assertSame([], $misnamed);
    }

    /**
     * @test
     */
    public function it_aliases_the_interfaces_of_its_services_to_them(): void
    {
        $this->load();

        $aliases = [
            OrderCancellerInterface::class => OrderCanceller::class,
            SalesOrderDataMapperInterface::class => CompositeSalesOrderDataMapper::class,
            PreQualifiedUploadableOrdersProviderInterface::class => PreQualifiedUploadableOrdersProvider::class,
            SetupProviderInterface::class => SetupProvider::class,
            WebhookRegistrarInterface::class => WebhookRegistrar::class,
            OrderUploaderInterface::class => OrderUploader::class,
            OrderResolverInterface::class => OrderResolver::class,
        ];

        foreach ($aliases as $interface => $implementation) {
            $this->assertContainerBuilderHasAlias($interface, $implementation);
            self::assertFalse($this->container->getAlias($interface)->isPublic(), sprintf('"%s" is private', $interface));
        }
    }

    /**
     * @test
     */
    public function the_shipmondo_client_sends_its_requests_through_the_plugins_two_http_clients(): void
    {
        $this->load();

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(Client::class, 3, new Reference(ShipmondoHttpClient::class));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(ShipmondoHttpClient::class, 0, new Reference('psr18.setono_sylius_shipmondo.http_client'));
        $this->assertContainerBuilderHasServiceDefinitionWithArgument(ShipmondoHttpClient::class, 1, new Reference('psr18.setono_sylius_shipmondo.pickup_points_http_client'));
    }

    /**
     * @test
     */
    public function the_shops_pickup_point_search_uses_the_shipmondo_client(): void
    {
        $this->load();

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(GetPickupPointsAction::class, 2, new Reference(Client::class));
    }

    /**
     * @test
     */
    public function it_autoconfigures_data_mappers_and_webhook_handlers(): void
    {
        $this->load();

        $autoconfigured = $this->container->getAutoconfiguredInstanceof();

        self::assertTrue($autoconfigured[SalesOrderDataMapperInterface::class]->hasTag('setono_sylius_shipmondo.sales_order_data_mapper'));
        self::assertTrue($autoconfigured[OrderWebhookHandlerInterface::class]->hasTag('setono_sylius_shipmondo.order_webhook_handler'));
    }

    /**
     * @test
     */
    public function it_prepends_the_framework_sylius_grid_and_sylius_ui_configuration(): void
    {
        (new SetonoSyliusShipmondoExtension())->prepend($this->container);

        $javascripts = static fn (string $template): array => [
            'blocks' => [
                'javascripts' => [
                    'template' => $template,
                ],
            ],
        ];

        self::assertSame([[
            'messenger' => [
                'buses' => [
                    'setono_sylius_shipmondo.command_bus' => [
                        'middleware' => [
                            'doctrine_transaction',
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
            'http_client' => [
                'scoped_clients' => [
                    'setono_sylius_shipmondo.http_client' => [
                        'scope' => 'https://(app|sandbox)\.shipmondo\.com/',
                        'timeout' => 10,
                        'max_duration' => 30,
                        'retry_failed' => false,
                    ],
                    'setono_sylius_shipmondo.pickup_points_http_client' => [
                        'scope' => 'https://(app|sandbox)\.shipmondo\.com/',
                        'timeout' => 5,
                        'max_duration' => 10,
                        'retry_failed' => false,
                    ],
                ],
            ],
        ]], $this->container->getExtensionConfig('framework'));

        self::assertSame([[
            'templates' => [
                'bulk_action' => [
                    'setono_sylius_shipmondo_retry_upload' => '@SetonoSyliusShipmondoPlugin/admin/order/grid/bulk_action/retry_upload.html.twig',
                ],
            ],
        ]], $this->container->getExtensionConfig('sylius_grid'));

        self::assertSame([[
            'events' => [
                'sylius.admin.order.show.sidebar' => [
                    'blocks' => [
                        'setono_sylius_shipmondo' => [
                            'template' => '@SetonoSyliusShipmondoPlugin/admin/order/show/_shipmondo.html.twig',
                            'priority' => 32,
                        ],
                    ],
                ],
                'sylius.admin.shipping_method.update.javascripts' => $javascripts('@SetonoSyliusShipmondoPlugin/admin/shipping_method/form/_javascripts.html.twig'),
                'sylius.admin.shipping_method.create.javascripts' => $javascripts('@SetonoSyliusShipmondoPlugin/admin/shipping_method/form/_javascripts.html.twig'),
                'sylius.shop.layout.javascripts' => $javascripts('@SetonoSyliusShipmondoPlugin/shop/_javascripts.html.twig'),
            ],
        ]], $this->container->getExtensionConfig('sylius_ui'));
    }

    /**
     * @test
     */
    public function it_hooks_order_cancellation_into_winzou_when_it_is_installed(): void
    {
        $this->registerEmptyExtension('winzou_state_machine');

        (new SetonoSyliusShipmondoExtension())->prepend($this->container);

        self::assertSame([[
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
        ]], $this->container->getExtensionConfig('winzou_state_machine'));
    }

    /**
     * @test
     */
    public function it_does_not_hook_winzou_when_it_is_not_installed(): void
    {
        (new SetonoSyliusShipmondoExtension())->prepend($this->container);

        self::assertSame([], $this->container->getExtensionConfig('winzou_state_machine'));
    }

    /**
     * @test
     */
    public function it_registers_the_pickup_point_type_with_doctrine_when_it_is_installed(): void
    {
        $this->registerEmptyExtension('doctrine');

        (new SetonoSyliusShipmondoExtension())->prepend($this->container);

        self::assertSame([[
            'dbal' => [
                'types' => [
                    'setono_sylius_shipmondo_pickup_point' => PickupPointType::class,
                ],
            ],
        ]], $this->container->getExtensionConfig('doctrine'));
    }

    /**
     * @test
     */
    public function it_does_not_configure_doctrine_when_it_is_not_installed(): void
    {
        (new SetonoSyliusShipmondoExtension())->prepend($this->container);

        self::assertSame([], $this->container->getExtensionConfig('doctrine'));
    }

    private function registerEmptyExtension(string $alias): void
    {
        $this->container->registerExtension(new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        });
    }
}
