<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Webmozart\Assert\Assert;

final class DeleteOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ClientInterface $shipmondoClient,
    ) {
    }

    public function __invoke(DeleteOrder $message): void
    {
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        $shipmondoId = $order->getShipmondoId();
        if (null === $shipmondoId) {
            return;
        }

        $this->shipmondoClient->salesOrders()->delete($shipmondoId);

        $order->setShipmondoId(null);
    }
}
