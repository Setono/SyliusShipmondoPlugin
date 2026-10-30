<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DependencyInjection;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractExtensionTestCase;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\DependencyInjection\SetonoSyliusShipmondoExtension;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\RemoteEventHandlerInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

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
        $this->assertContainerBuilderHasService('setono_sylius_shipmondo.webhook.webhook_parser');
    }

    /**
     * @test
     */
    public function it_autoconfigures_data_mappers_and_remote_event_handlers(): void
    {
        $this->load();

        $autoconfigured = $this->container->getAutoconfiguredInstanceof();

        self::assertTrue($autoconfigured[SalesOrderDataMapperInterface::class]->hasTag('setono_sylius_shipmondo.sales_order_data_mapper'));
        self::assertTrue($autoconfigured[RemoteEventHandlerInterface::class]->hasTag('setono_sylius_shipmondo.remote_event_handler'));
    }

    /**
     * @test
     */
    public function it_prepends_the_framework_and_sylius_ui_configuration(): void
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
        ]], $this->container->getExtensionConfig('framework'));

        self::assertSame([[
            'events' => [
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
        $this->container->registerExtension(new class() extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'winzou_state_machine';
            }
        });

        (new SetonoSyliusShipmondoExtension())->prepend($this->container);

        self::assertSame([[
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
}
