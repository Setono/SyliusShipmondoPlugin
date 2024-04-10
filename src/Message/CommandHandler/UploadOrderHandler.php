<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\Shipmondo\Client\ClientInterface;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Workflow\WorkflowInterface;
use Webmozart\Assert\Assert;

final class UploadOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ClientInterface $shipmondoClient,
        private readonly SalesOrderDataMapperInterface $salesOrderDataMapper,
        private readonly WorkflowInterface $orderWorkflow,
    ) {
    }

    public function __invoke(UploadOrder $message): void
    {
        /** @var OrderInterface|null $order */
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', (string) $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        if (null !== $message->version && $order->getVersion() !== $message->version) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s has been updated since it was tried to be uploaded', (string) $message->order));
        }

        $salesOrder = $this->salesOrderDataMapper->map($order);

        $this->shipmondoClient->salesOrders()->create($salesOrder);

        $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_COMPLETE_UPLOAD);
    }
}
