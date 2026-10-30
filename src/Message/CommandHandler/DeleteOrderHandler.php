<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Exception\NotFoundException;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Webmozart\Assert\Assert;

/**
 * Deletes an order's sales order in Shipmondo.
 *
 * Handled synchronously (the default), this runs inside the Sylius order cancellation, so a failing
 * Shipmondo call must not throw: the command bus's doctrine_transaction middleware would roll back its
 * nested transaction, which marks the cancellation's own transaction rollback-only and makes the cancel
 * fail. A failure is logged instead, and the order keeps its Shipmondo id.
 *
 * Returns whether the sales order is gone from Shipmondo, so the dispatcher can tell the user.
 */
final class DeleteOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ClientInterface $shipmondoClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteOrder $message): bool
    {
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        $shipmondoId = $order->getShipmondoId();
        if (null === $shipmondoId) {
            return true;
        }

        try {
            $this->shipmondoClient->salesOrders()->delete($shipmondoId);
        } catch (NotFoundException) {
            // the sales order was already deleted in Shipmondo, which is what we wanted
        } catch (\Throwable $e) {
            $this->logger->error('Could not delete the Shipmondo sales order {shipmondoId} of the cancelled order {order}', [
                'shipmondoId' => $shipmondoId,
                'order' => $order->getNumber(),
                'exception' => $e,
            ]);

            return false;
        }

        $order->setShipmondoId(null);

        return true;
    }
}
