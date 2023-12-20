<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\DispatchOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Webmozart\Assert\Assert;

final class DispatchOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ClientInterface $shipmondoClient,
        private readonly SalesOrderDataMapperInterface $salesOrderDataMapper,
    ) {
    }

    public function __invoke(DispatchOrder $message): void
    {
        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', (string) $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        if (null !== $message->version && $order->getVersion() !== $message->version) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s has been updated since it was dispatched', (string) $message->order));
        }

        $salesOrder = $this->salesOrderDataMapper->map($order);

        $this->shipmondoClient->salesOrders()->create($salesOrder);
    }
}
