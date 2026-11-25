<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Setono\Doctrine\ORMTrait;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\Canceller\OrderCancellerInterface;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Failure\RecordedShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Linker\OrderLinkerInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Provider\UnlinkedOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderUploader implements OrderUploaderInterface
{
    use ORMTrait;

    public function __construct(
        private readonly PreQualifiedUploadableOrdersProviderInterface $preQualifiedUploadableOrdersProvider,
        private readonly UnlinkedOrdersProviderInterface $unlinkedOrdersProvider,
        private readonly OrderLinkerInterface $orderLinker,
        private readonly MessageBusInterface $commandBus,
        private readonly WorkflowInterface $orderWorkflow,
        private readonly ClientInterface $shipmondoClient,
        private readonly SalesOrderDataMapperInterface $salesOrderDataMapper,
        private readonly OrderCancellerInterface $orderCanceller,
        private readonly LoggerInterface $logger,
        ManagerRegistry $managerRegistry,
    ) {
        $this->managerRegistry = $managerRegistry;
    }

    public function upload(): array
    {
        $run = new UploadRun($this->logger);

        $this->lookUpSalesOrders($run);
        if (!$run->stopped()) {
            $this->uploadPendingOrders($run);
        }

        return $run->problems();
    }

    public function uploadOrder(OrderInterface $order, ?int $version = null): UploadOutcome
    {
        if (!$this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_COMPLETE_UPLOAD)) {
            $this->log($order, UploadOutcome::Skipped);

            return UploadOutcome::Skipped;
        }

        if (null !== $version && $order->getVersion() !== $version) {
            return $this->settle($order, UploadOutcome::ChangedWhileUploading);
        }

        try {
            $salesOrder = new SalesOrderRequest();
            $this->salesOrderDataMapper->map($order, $salesOrder);
        } catch (\Throwable $e) {
            // Shipmondo has no sales order for the order yet, also when a data mapper's read from Shipmondo failed
            $failure = ShipmondoFailure::of($e);

            return $this->settle($order, self::outcomeOf($failure), failure: $failure);
        }

        try {
            $response = $this->shipmondoClient->salesOrders()->create($salesOrder);
        } catch (\Throwable $e) {
            $failure = ShipmondoFailure::of($e);

            return $this->settle($order, self::outcomeOf($failure), failure: $failure, createFailed: true);
        }

        return $this->settle($order, UploadOutcome::Uploaded, salesOrderId: $response->id);
    }

    /**
     * The order was failed when it was loaded, e.g. when the admin opened its page, but something may have changed it
     * since, so its row is locked and it is read again before it is retried
     */
    public function retry(OrderInterface $order): bool
    {
        $manager = $this->getManager($order);

        /** @var bool $retried */
        $retried = $manager->wrapInTransaction(function () use ($manager, $order): bool {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            // retry also leaves uploading, but only the upload decides how an uploading order goes on
            if (OrderInterface::SHIPMONDO_STATE_FAILED !== $order->getShipmondoState()) {
                return false;
            }

            $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_RETRY);

            return true;
        });

        $this->logger->info(
            $retried ?
                'The upload of the order {order} was retried, so the order is pending for the next upload run' :
                'The upload of the order {order} was not retried, because its Shipmondo state is {state}, not failed',
            ['order' => $order->getNumber(), 'state' => $order->getShipmondoState()],
        );

        return $retried;
    }

    /**
     * Uploads every pending order that qualifies for upload, until a stop rule stops the run
     */
    private function uploadPendingOrders(UploadRun $run): void
    {
        foreach ($this->preQualifiedUploadableOrdersProvider->getOrders() as $order) {
            if (!$this->claim($order)) {
                continue;
            }

            $outcome = $this->requestUpload($order);
            // how an upload sent to an asynchronous transport goes is unknown here
            if (null === $outcome) {
                continue;
            }

            $run->recordUpload($order, $outcome);
            if ($run->stopped()) {
                return;
            }
        }
    }

    /**
     * Looks up the sales order of every unlinked order that is due a sales order lookup, newest first, and settles the
     * order by what it finds, until a stop rule stops the lookups: the remaining ones wait for the next upload run
     */
    private function lookUpSalesOrders(UploadRun $run): void
    {
        foreach ($this->unlinkedOrdersProvider->getOrders() as $order) {
            $run->recordLookup($order, $this->orderLinker->link($order));
            if ($run->lookupsStopped()) {
                return;
            }
        }
    }

    /**
     * Puts a pending order in uploading, so no other upload run uploads it too.
     *
     * The upload run loaded the order a while ago. If something changed the order since (another run claimed it, an
     * admin cancelled it, its payment was refunded), it may no longer qualify for upload, so it is left pending for the
     * next upload run to check again. The order's row is locked while this is decided, so nothing changes it in between.
     */
    private function claim(OrderInterface $order): bool
    {
        $loadedVersion = $order->getVersion();
        $manager = $this->getManager($order);

        /** @var bool $claimed */
        $claimed = $manager->wrapInTransaction(function () use ($manager, $order, $loadedVersion): bool {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            if ($order->getVersion() !== $loadedVersion || !$this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_START_UPLOAD)) {
                return false;
            }

            $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_START_UPLOAD);

            return true;
        });

        return $claimed;
    }

    /**
     * Requests the upload of a claimed order through the UploadOrder command. Returns the upload's outcome when the
     * command is handled synchronously, and null when it is sent to an asynchronous transport
     */
    private function requestUpload(OrderInterface $order): ?UploadOutcome
    {
        try {
            $envelope = $this->commandBus->dispatch(new UploadOrder($order));
        } catch (DelayedMessageHandlingException $e) {
            // the upload was settled, but a command it requested for afterwards failed, e.g. the DeleteOrder command for
            // an order that was cancelled while it was uploading couldn't be sent to its transport
            $this->logger->error('The upload of the order {order} was settled, but a command it requested afterwards failed', [
                'order' => $order->getNumber(),
                'exception' => $e,
            ]);

            // the middleware gives the exception the envelope of the command it dispatched
            $envelope = $e->getEnvelope() ?? throw $e;
        }

        $result = $envelope->last(HandledStamp::class)?->getResult();

        return $result instanceof UploadOutcome ? $result : null;
    }

    /**
     * Records how the upload went on the order: moves its Shipmondo state, which links it to its sales order (or to none),
     * and records the Shipmondo failure the upload ended in (or none), so an admin can tell why the order is in it.
     *
     * Something may have changed the order while Shipmondo was called, e.g. its payment was captured. Flushing the stale
     * order would then fail and close the entity manager, leaving the order in uploading and the sales order Shipmondo
     * just created unlinked. So the order's row is locked and read again first.
     *
     * The order may even have been cancelled. Its cancellation left it to its upload, because the order was uploading,
     * so it becomes cancelled here, once it is pending or failed, or once the sales order Shipmondo just created is
     * deleted. An unlinked order stays unlinked, since which sales order Shipmondo may have created is unknown: the
     * sales order lookup settles it later
     *
     * @param bool $createFailed whether the call that creates the sales order failed, rather than an earlier step
     */
    private function settle(
        OrderInterface $order,
        UploadOutcome $outcome,
        ?int $salesOrderId = null,
        ?ShipmondoFailure $failure = null,
        bool $createFailed = false,
    ): UploadOutcome {
        $manager = $this->getManager($order);

        $manager->wrapInTransaction(function () use ($manager, $order, $outcome, $salesOrderId, $failure, $createFailed): void {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            $this->orderWorkflow->apply($order, self::transitionOf($outcome, $createFailed), [
                OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId,
                OrderWorkflow::CONTEXT_SHIPMONDO_FAILURE => null === $failure ? null : RecordedShipmondoFailure::of($failure),
            ]);
        });

        $this->log($order, $outcome, $failure);

        if (OrderInterface::STATE_CANCELLED === $order->getState()) {
            $this->logger->info('The order {order} was cancelled while it was uploading, so it becomes cancelled once Shipmondo has no sales order for it', [
                'order' => $order->getNumber(),
                'salesOrder' => $order->getShipmondoId(),
            ]);

            $this->cancel($order);
        }

        return $outcome;
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

    /**
     * A failure that says nothing about the order puts it back to pending. A 404 counts as rejected: on creating a
     * sales order, it most likely means something shop-wide is wrong, which the upload run's stop rule for failed uploads
     * catches
     */
    private static function outcomeOf(ShipmondoFailure $failure): UploadOutcome
    {
        return match ($failure->kind) {
            ShipmondoFailureKind::CredentialsFailure => UploadOutcome::CredentialsFailure,
            ShipmondoFailureKind::RateLimited => UploadOutcome::RateLimited,
            ShipmondoFailureKind::TemporaryFailure => UploadOutcome::TemporaryFailure,
            ShipmondoFailureKind::NotFound, ShipmondoFailureKind::Rejected => UploadOutcome::Rejected,
            ShipmondoFailureKind::UnexpectedFailure => UploadOutcome::UnexpectedFailure,
            ShipmondoFailureKind::UnreadSuccess => UploadOutcome::UnreadSuccess,
        };
    }

    /**
     * The transition that settles an upload. When the call that creates the sales order ends in an unread success, or
     * fails temporarily, e.g. with a server error or no answer in time, Shipmondo may have created the sales order before
     * the failure. It accepts two sales orders with the same order id, so uploading the order again could make a
     * duplicate: the order becomes unlinked instead, for a sales order lookup to settle
     */
    private static function transitionOf(UploadOutcome $outcome, bool $createFailed): string
    {
        return match ($outcome) {
            UploadOutcome::Uploaded => OrderWorkflow::TRANSITION_COMPLETE_UPLOAD,
            UploadOutcome::UnreadSuccess, UploadOutcome::TemporaryFailure => $createFailed ? OrderWorkflow::TRANSITION_COMPLETE_UPLOAD_UNLINKED : OrderWorkflow::TRANSITION_RETRY,
            UploadOutcome::Rejected, UploadOutcome::UnexpectedFailure => OrderWorkflow::TRANSITION_FAIL,
            default => OrderWorkflow::TRANSITION_RETRY,
        };
    }

    private function log(OrderInterface $order, UploadOutcome $outcome, ?ShipmondoFailure $failure = null): void
    {
        [$level, $message] = match ($outcome) {
            UploadOutcome::Uploaded => [LogLevel::INFO, 'The order {order} was uploaded to Shipmondo as the sales order {salesOrder}'],
            UploadOutcome::UnreadSuccess => [LogLevel::WARNING, 'Shipmondo answered the upload of the order {order} with a success that was cut off or could not be read, so Shipmondo created a sales order for it, but which is unknown. The order is unlinked until a sales order lookup links it'],
            UploadOutcome::Rejected => [LogLevel::ERROR, 'Shipmondo rejected the upload of the order {order}, so the order is failed'],
            UploadOutcome::UnexpectedFailure => [LogLevel::ERROR, 'The upload of the order {order} failed unexpectedly, so the order is failed'],
            UploadOutcome::CredentialsFailure => [LogLevel::ERROR, 'Shipmondo did not accept the API credentials when uploading the order {order}, so the order goes back to pending'],
            UploadOutcome::RateLimited => [LogLevel::WARNING, 'Shipmondo rate limited the upload of the order {order}, so the order goes back to pending'],
            UploadOutcome::TemporaryFailure => [LogLevel::WARNING, OrderInterface::SHIPMONDO_STATE_UNLINKED === $order->getShipmondoState() ?
                'The call that creates the sales order of the order {order} failed temporarily, so Shipmondo may have created it before the failure. The order is unlinked until a sales order lookup settles it' :
                'The upload of the order {order} failed temporarily before its sales order was created, so the order goes back to pending'],
            UploadOutcome::ChangedWhileUploading => [LogLevel::INFO, 'The order {order} changed while it was uploading, so it goes back to pending without being uploaded'],
            UploadOutcome::Skipped => [LogLevel::INFO, 'The order {order} was not uploaded, because its Shipmondo state is {state}, not uploading'],
        };

        $context = [
            'order' => $order->getNumber(),
            'outcome' => $outcome->value,
            'state' => $order->getShipmondoState(),
            'salesOrder' => $order->getShipmondoId(),
        ];

        if (null !== $failure) {
            $context += $failure->logContext();
        }

        $this->logger->log($level, $message, $context);
    }
}
