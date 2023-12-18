<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\SyliusShipmondoPlugin\Message\Command\DispatchOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Webmozart\Assert\Assert;

final class DispatchOrderHandler
{
    public function __construct(private readonly OrderRepositoryInterface $orderRepository)
    {
    }

    public function __invoke(DispatchOrder $message): void
    {
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', (string) $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        dump('Dispatching order with id ' . $order->getId());
    }
}
