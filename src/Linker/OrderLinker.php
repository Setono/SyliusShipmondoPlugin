<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Linker;

use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Setono\Doctrine\ORMTrait;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\Shipmondo\Request\CollectionRequestOptions;
use Setono\Shipmondo\Request\SalesOrderSearch;
use Setono\Shipmondo\Response\SalesOrder\SalesOrder;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\OrderIdProviderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\Exception\ExceptionInterface as MessengerException;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderLinker implements OrderLinkerInterface
{
    use ORMTrait;

    /**
     * The name of the webhook a lookup that links an order hands the status_update webhook handlers
     */
    public const CATCH_UP_WEBHOOK_NAME = 'shipmondo.sales_order_lookup';

    /**
     * Shipmondo lists at most this many sales orders per page. A sales order lookup reads one page, which holds every
     * sales order with one order id
     */
    private const SALES_ORDERS_PER_PAGE = 25;

    /**
     * The payment statuses of a sales order whose payment Shipmondo may have captured, voided or refunded
     */
    private const PAYMENT_STATUSES_THAT_MAY_HAVE_CHANGED = ['paid', 'partially_paid', 'marked_as_paid', 'voided', 'refunded', 'partially_refunded'];

    public function __construct(
        private readonly OrderIdProviderInterface $orderIdProvider,
        private readonly ClientInterface $shipmondoClient,
        private readonly WorkflowInterface $orderWorkflow,
        private readonly OrderCancellerInterface $orderCanceller,
        private readonly OrderWebhookHandlers $orderWebhookHandlers,
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
            $salesOrders = $this->findSalesOrders($order);
        } catch (\Throwable $e) {
            $failure = ShipmondoFailure::of($e);

            $this->logger->log(
                ShipmondoFailureKind::TemporaryFailure === $failure->kind || ShipmondoFailureKind::RateLimited === $failure->kind ? LogLevel::WARNING : LogLevel::ERROR,
                'The sales order lookup of the unlinked order {order} failed, so the order stays unlinked for the next upload run',
                ['order' => $order->getNumber()] + $failure->logContext(),
            );

            return $failure;
        }

        if (count($salesOrders) > 1) {
            $this->logger->error('A sales order lookup found several sales orders with the order id of the unlinked order {order}, so the order stays unlinked: they may be duplicates, or another shop\'s on the same Shipmondo account. Check the sales orders {salesOrders} in Shipmondo', [
                'order' => $order->getNumber(),
                'salesOrders' => array_map(static fn (SalesOrder $salesOrder): int => $salesOrder->id, $salesOrders),
            ]);

            return null;
        }

        $salesOrder = $salesOrders[0] ?? null;
        $salesOrderId = $salesOrder?->id;
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

        if (null === $salesOrder) {
            $this->logger->info('A sales order lookup found no sales order for the unlinked order {order}, so the order goes back to pending to be uploaded again', [
                'order' => $order->getNumber(),
            ]);
        } else {
            $this->logger->info('A sales order lookup found the sales order {salesOrder} for the unlinked order {order}, so the order is uploaded and linked to it', [
                'order' => $order->getNumber(),
                'salesOrder' => $salesOrderId,
            ]);

            $this->catchUp($order, $salesOrder);
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
     * The sales orders Shipmondo has with the exact order id the order's upload sent. Shipmondo's order_id filter
     * matches exactly, but doesn't document it, so each sales order's order id is compared too
     *
     * @return list<SalesOrder>
     */
    private function findSalesOrders(OrderInterface $order): array
    {
        $orderId = $this->orderIdProvider->getOrderId($order);

        // no archived filter, so an archived sales order is found too
        $salesOrders = $this->shipmondoClient->salesOrders()->search(
            new SalesOrderSearch(orderId: $orderId),
            new CollectionRequestOptions(perPage: self::SALES_ORDERS_PER_PAGE),
        );

        $found = [];
        foreach ($salesOrders as $salesOrder) {
            if ($orderId === ($salesOrder->raw['order_id'] ?? null)) {
                $found[] = $salesOrder;
            }
        }

        return $found;
    }

    /**
     * Shipmondo's webhooks about the sales order were lost while the order was unlinked, since no order was linked to
     * it, so the order catches up on the sales order as the orders/status_update webhook would have. A sales order holds
     * only its payment's current state, not what happened to it, so a capture, a void or a refund can't be caught up on:
     * an admin is warned to check the payment instead
     */
    private function catchUp(OrderInterface $order, SalesOrder $salesOrder): void
    {
        $this->runStatusUpdateHandlers($order, $salesOrder);

        $paymentStatus = $salesOrder->raw['payment_status'] ?? null;
        if (in_array($paymentStatus, self::PAYMENT_STATUSES_THAT_MAY_HAVE_CHANGED, true)) {
            $this->logger->warning('The sales order {salesOrder}, which a sales order lookup linked the order {order} to, has the payment status {paymentStatus}, so Shipmondo may have captured, voided or refunded its payment while the order was unlinked, and Sylius doesn\'t know. Check the order\'s payment in Sylius', [
                'order' => $order->getNumber(),
                'salesOrder' => $salesOrder->id,
                'paymentStatus' => $paymentStatus,
            ]);
        }
    }

    /**
     * Runs the handlers of the orders/status_update webhook with the sales order, on the order as the database has it,
     * locked. When one fails, what they changed on the order, its shipments and its payments is discarded, and the failure
     * is logged
     */
    private function runStatusUpdateHandlers(OrderInterface $order, SalesOrder $salesOrder): void
    {
        $handlers = $this->orderWebhookHandlers->forAction(WebhookAction::StatusUpdate);
        if ([] === $handlers) {
            return;
        }

        $webhook = new RemoteEvent(self::CATCH_UP_WEBHOOK_NAME, $salesOrder->raw, WebhookResourceName::Orders, WebhookAction::StatusUpdate);
        $manager = $this->getManager($order);

        /** @var \Throwable|null $failure */
        $failure = $manager->wrapInTransaction(function () use ($manager, $order, $handlers, $webhook): ?\Throwable {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            // caught here, since a failure that escapes the transaction would close the entity manager, and so end the run
            try {
                foreach ($handlers as $handler) {
                    $handler->handle($order, $webhook);
                }
            } catch (\Throwable $e) {
                // reading the order, its shipments and its payments again discards what the handlers changed
                $manager->refresh($order);

                return $e;
            }

            return null;
        });

        if (null !== $failure) {
            $this->logger->error('A webhook handler failed to catch the order {order} up on its sales order {salesOrder}, which a sales order lookup linked it to, so what the handlers changed on the order, its shipments and its payments is discarded. The order stays linked, and Shipmondo\'s webhooks about the sales order reach it from now on. Check the order against its sales order in Shipmondo', [
                'order' => $order->getNumber(),
                'salesOrder' => $salesOrder->id,
                'exception' => $failure,
            ]);
        }
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
