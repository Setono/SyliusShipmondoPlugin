<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Linker;

use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Setono\Doctrine\ORMTrait;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Request\CollectionRequestOptions;
use Setono\Shipmondo\Request\SalesOrderSearch;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\OrderIdProviderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderLinker implements OrderLinkerInterface
{
    use ORMTrait;

    /**
     * Shipmondo lists at most this many sales orders per page. A sales order lookup reads one page, which holds every
     * sales order with one order id
     */
    private const SALES_ORDERS_PER_PAGE = 25;

    public function __construct(
        private readonly OrderIdProviderInterface $orderIdProvider,
        private readonly ClientInterface $shipmondoClient,
        private readonly WorkflowInterface $orderWorkflow,
        private readonly OrderCancellerInterface $orderCanceller,
        private readonly LoggerInterface $logger,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function link(OrderInterface $order): ?ShipmondoFailure
    {
        if (OrderInterface::SHIPMONDO_STATE_UNLINKED !== $order->getShipmondoState()) {
            return null;
        }

        $loadedVersion = $order->getVersion();

        try {
            $salesOrderIds = $this->findSalesOrders($order);
        } catch (\Throwable $e) {
            $failure = ShipmondoFailure::of($e);

            $this->logger->log(
                ShipmondoFailureKind::TemporaryFailure === $failure->kind || ShipmondoFailureKind::RateLimited === $failure->kind ? LogLevel::WARNING : LogLevel::ERROR,
                'The sales order lookup of the unlinked order {order} failed, so the order stays unlinked for the next upload run',
                ['order' => $order->getNumber()] + $failure->logContext(),
            );

            return $failure;
        }

        if (count($salesOrderIds) > 1) {
            $this->logger->error('A sales order lookup found several sales orders with the order id of the unlinked order {order}, so the order stays unlinked: they may be duplicates, or another shop\'s on the same Shipmondo account. Check the sales orders {salesOrders} in Shipmondo', [
                'order' => $order->getNumber(),
                'salesOrders' => $salesOrderIds,
            ]);

            return null;
        }

        $salesOrderId = $salesOrderIds[0] ?? null;
        $transition = null === $salesOrderId ? OrderWorkflow::TRANSITION_RETRY : OrderWorkflow::TRANSITION_LINK;
        $manager = $this->getManager($order);

        /** @var bool $settled */
        $settled = $manager->wrapInTransaction(function () use ($manager, $order, $loadedVersion, $transition, $salesOrderId): bool {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            if ($order->getVersion() !== $loadedVersion || !$this->orderWorkflow->can($order, $transition)) {
                return false;
            }

            $this->orderWorkflow->apply($order, $transition, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId]);

            return true;
        });

        if (!$settled) {
            $this->logger->info('The sales order lookup of the order {order} was not settled, because the order changed while Shipmondo was searched, or a guard on the order workflow holds it back, so it is left as it is', [
                'order' => $order->getNumber(),
                'state' => $order->getShipmondoState(),
            ]);

            return null;
        }

        if (null === $salesOrderId) {
            $this->logger->info('A sales order lookup found no sales order for the unlinked order {order}, so the order goes back to pending to be uploaded again', [
                'order' => $order->getNumber(),
            ]);
        } else {
            $this->logger->info('A sales order lookup found the sales order {salesOrder} for the unlinked order {order}, so the order is uploaded and linked to it', [
                'order' => $order->getNumber(),
                'salesOrder' => $salesOrderId,
            ]);
        }

        if (OrderInterface::STATE_CANCELLED === $order->getState()) {
            $this->logger->info('The unlinked order {order} was cancelled, so it becomes cancelled once Shipmondo has no sales order for it', [
                'order' => $order->getNumber(),
                'salesOrder' => $salesOrderId,
            ]);

            try {
                $this->cancel($order);
            } catch (MessengerException $e) {
                $this->logger->error('The sales order of the cancelled order {order} could not be deleted, because the command that deletes it failed, so the order stays uploaded and linked to it. Delete the sales order in Shipmondo', [
                    'order' => $order->getNumber(),
                    'salesOrder' => $salesOrderId,
                    'exception' => $e,
                ]);
            }
        }

        return null;
    }

    /**
     * The ids of the sales orders Shipmondo has with the exact order id the order's upload sent. Shipmondo's order_id
     * filter matches exactly, but doesn't document it, so each sales order's order id is compared too
     *
     * @return list<int>
     */
    private function findSalesOrders(OrderInterface $order): array
    {
        $orderId = $this->orderIdProvider->getOrderId($order);

        // no archived filter, so an archived sales order is found too
        $salesOrders = $this->shipmondoClient->salesOrders()->search(
            new SalesOrderSearch(orderId: $orderId),
            new CollectionRequestOptions(perPage: self::SALES_ORDERS_PER_PAGE),
        );

        $ids = [];
        foreach ($salesOrders as $salesOrder) {
            if ($orderId === ($salesOrder->raw['order_id'] ?? null)) {
                $ids[] = $salesOrder->id;
            }
        }

        return $ids;
    }

    /**
     * Makes the Shipmondo state of an order that is cancelled in Sylius cancelled too, or deletes its sales order first,
     * on the order as the database has it, locked
     */
    private function cancel(OrderInterface $order): void
    {
        $manager = $this->getManager($order);

        $manager->wrapInTransaction(function () use ($manager, $order): void {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            $this->orderCanceller->cancel($order);
        });
    }
}
