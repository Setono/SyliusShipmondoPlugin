<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DependencyInjection\Compiler;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Setono\SyliusShipmondoPlugin\DependencyInjection\Compiler\RegisterOrderWebhookHandlersPass;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\SalesOrderDeletedHandler;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\SalesOrderPaymentHandler;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\SalesOrderShippedHandler;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

final class RegisterOrderWebhookHandlersPassTest extends AbstractCompilerPassTestCase
{
    private const HANDLERS = 'setono_sylius_shipmondo.webhook.order_webhook_handlers';

    private const TAG = 'setono_sylius_shipmondo.order_webhook_handler';

    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new RegisterOrderWebhookHandlersPass());
    }

    /**
     * @test
     */
    public function it_maps_each_action_to_the_handlers_subscribed_to_it_by_priority(): void
    {
        $this->registerService(self::HANDLERS, OrderWebhookHandlers::class)->addArgument([])->setPublic(true);
        $this->registerService('shipped', SalesOrderShippedHandler::class)->addTag(self::TAG, ['priority' => 10]);
        $this->registerService('deleted', SalesOrderDeletedHandler::class)->addTag(self::TAG);
        $this->setParameter('payment_handler.class', SalesOrderPaymentHandler::class);
        $this->registerService('payment', '%payment_handler.class%')->addTag(self::TAG);
        $this->registerService('shipped_first', SalesOrderShippedHandler::class)->addTag(self::TAG, ['priority' => 20]);
        $this->registerService('not_tagged', SalesOrderDeletedHandler::class);

        $this->compile();

        $this->assertContainerBuilderHasServiceDefinitionWithArgument(self::HANDLERS, 0, [
            'create_shipment' => new IteratorArgument([new Reference('shipped_first'), new Reference('shipped')]),
            'status_update' => new IteratorArgument([new Reference('shipped_first'), new Reference('shipped')]),
            'delete' => new IteratorArgument([new Reference('deleted')]),
            'payment_captured' => new IteratorArgument([new Reference('payment')]),
            'payment_voided' => new IteratorArgument([new Reference('payment')]),
        ]);
    }

    /**
     * @test
     */
    public function it_rejects_a_tagged_service_that_is_not_an_order_webhook_handler(): void
    {
        $this->registerService(self::HANDLERS, OrderWebhookHandlers::class)->addArgument([]);
        $this->registerService('not_a_handler', \stdClass::class)->addTag(self::TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The service "not_a_handler" is tagged "setono_sylius_shipmondo.order_webhook_handler", so it must implement "Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlerInterface"');

        $this->compile();
    }

    /**
     * @test
     */
    public function it_rejects_a_tagged_service_whose_class_does_not_exist(): void
    {
        $this->registerService(self::HANDLERS, OrderWebhookHandlers::class)->addArgument([]);
        $this->registerService('missing', 'App\Webhook\MissingHandler')->addTag(self::TAG);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The service "missing" is tagged "setono_sylius_shipmondo.order_webhook_handler", so it must implement');

        $this->compile();
    }

    /**
     * @test
     */
    public function it_does_nothing_without_the_order_webhook_handlers_service(): void
    {
        $this->registerService('shipped', SalesOrderShippedHandler::class)->addTag(self::TAG);

        $this->compile();

        $this->assertContainerBuilderNotHasService(self::HANDLERS);
    }
}
