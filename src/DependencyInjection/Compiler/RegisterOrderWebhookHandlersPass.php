<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\DependencyInjection\Compiler;

use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlerInterface;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PriorityTaggedServiceTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Reads the actions each webhook handler subscribes to, once, and gives the webhook handlers service the handlers of
 * each action, by the tag's priority. The webhook consumer and the webhook registrar both read that service.
 */
final class RegisterOrderWebhookHandlersPass implements CompilerPassInterface
{
    use PriorityTaggedServiceTrait;

    private const HANDLERS = 'setono_sylius_shipmondo.webhook.order_webhook_handlers';

    private const TAG = 'setono_sylius_shipmondo.order_webhook_handler';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::HANDLERS)) {
            return;
        }

        /** @var array<string, list<Reference>> $handlers */
        $handlers = [];
        foreach ($this->findAndSortTaggedServices(self::TAG, $container) as $reference) {
            foreach (self::handlerClass($container, (string) $reference)::getSubscribedActions() as $action) {
                $handlers[$action->value][] = $reference;
            }
        }

        $container->getDefinition(self::HANDLERS)->setArgument(0, array_map(
            static fn (array $references): IteratorArgument => new IteratorArgument($references),
            $handlers,
        ));
    }

    /**
     * @return class-string<OrderWebhookHandlerInterface>
     */
    private static function handlerClass(ContainerBuilder $container, string $id): string
    {
        $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass());
        $reflection = $container->getReflectionClass(is_string($class) ? $class : null);

        if (null === $reflection || !$reflection->implementsInterface(OrderWebhookHandlerInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'The service "%s" is tagged "%s", so it must implement "%s"',
                $id,
                self::TAG,
                OrderWebhookHandlerInterface::class,
            ));
        }

        /** @var class-string<OrderWebhookHandlerInterface> $handlerClass */
        $handlerClass = $reflection->getName();

        return $handlerClass;
    }
}
