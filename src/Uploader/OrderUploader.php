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
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderUploader implements OrderUploaderInterface
{
    use ORMTrait;

    /**
     * Shipmondo answers 422 whether the order or a shop-wide setting (e.g. a deleted payment gateway) is the problem,
     * so this many rejected uploads in a row stop a synchronous upload run
     */
    private const MAX_REJECTED_UPLOADS_IN_A_ROW = 3;

    public function __construct(
        private readonly PreQualifiedUploadableOrdersProviderInterface $preQualifiedUploadableOrdersProvider,
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

    public function upload(): ?UploadOutcome
    {
        $rejectedInARow = 0;

        foreach ($this->preQualifiedUploadableOrdersProvider->getOrders() as $order) {
            if (!$this->claim($order)) {
                continue;
            }

            $outcome = $this->requestUpload($order);

            if (UploadOutcome::CredentialsFailure === $outcome || UploadOutcome::RateLimited === $outcome) {
                $this->logger->error('The upload run stopped, because Shipmondo answered the upload of the order {order} with a {outcome}. The remaining orders stay pending for the next run', [
                    'order' => $order->getNumber(),
                    'outcome' => $outcome->value,
                ]);

                return $outcome;
            }

            $rejectedInARow = match ($outcome) {
                UploadOutcome::Uploaded => 0,
                UploadOutcome::Rejected => $rejectedInARow + 1,
                default => $rejectedInARow,
            };

            if (self::MAX_REJECTED_UPLOADS_IN_A_ROW === $rejectedInARow) {
                $this->logger->error('The upload run stopped after {count} rejected uploads in a row, which suggests a shop-wide problem, such as a payment gateway or shipment template that no longer exists in Shipmondo. The remaining orders stay pending for the next run', [
                    'count' => $rejectedInARow,
                ]);

                return UploadOutcome::Rejected;
            }
        }

        return null;
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

            $response = $this->shipmondoClient->salesOrders()->create($salesOrder);
        } catch (\Throwable $e) {
            $failure = ShipmondoFailure::of($e);

            return $this->settle($order, self::outcomeOf($failure), failure: $failure);
        }

        return $this->settle($order, UploadOutcome::Uploaded, salesOrderId: $response->id);
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
     * Records how the upload went on the order: moves its Shipmondo state, which links it to its sales order (or to none).
     *
     * Something may have changed the order while Shipmondo was called, e.g. its payment was captured. Flushing the stale
     * order would then fail and close the entity manager, leaving the order in uploading and the sales order Shipmondo
     * just created unlinked. So the order's row is locked and read again first.
     *
     * The order may even have been cancelled. Its cancellation left it to its upload, because the order was uploading,
     * so it becomes cancelled here, once it is pending or failed, or once the sales order Shipmondo just created is
     * deleted
     */
    private function settle(
        OrderInterface $order,
        UploadOutcome $outcome,
        ?int $salesOrderId = null,
        ?ShipmondoFailure $failure = null,
    ): UploadOutcome {
        $manager = $this->getManager($order);

        $manager->wrapInTransaction(function () use ($manager, $order, $outcome, $salesOrderId): void {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            $this->orderWorkflow->apply($order, match ($outcome) {
                UploadOutcome::Uploaded => OrderWorkflow::TRANSITION_COMPLETE_UPLOAD,
                UploadOutcome::Rejected, UploadOutcome::UnexpectedFailure => OrderWorkflow::TRANSITION_FAIL,
                default => OrderWorkflow::TRANSITION_RETRY,
            }, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId]);
        });

        $this->log($order, $outcome, $failure);

        if (OrderInterface::STATE_CANCELLED === $order->getState()) {
            $this->logger->info('The order {order} was cancelled while it was uploading, so it becomes cancelled once Shipmondo has no sales order for it', [
                'order' => $order->getNumber(),
                'salesOrder' => $order->getShipmondoId(),
            ]);

            $manager->wrapInTransaction(function () use ($manager, $order): void {
                $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

                $this->orderCanceller->cancel($order);
            });
        }

        return $outcome;
    }

    /**
     * A failure that says nothing about the order puts it back to pending. A 404 counts as rejected: on creating a
     * sales order, it most likely means something shop-wide is wrong, which the upload run's stop rule catches
     */
    private static function outcomeOf(ShipmondoFailure $failure): UploadOutcome
    {
        return match ($failure->kind) {
            ShipmondoFailureKind::CredentialsFailure => UploadOutcome::CredentialsFailure,
            ShipmondoFailureKind::RateLimited => UploadOutcome::RateLimited,
            ShipmondoFailureKind::TemporaryFailure => UploadOutcome::TemporaryFailure,
            ShipmondoFailureKind::NotFound, ShipmondoFailureKind::Rejected => UploadOutcome::Rejected,
            ShipmondoFailureKind::UnexpectedFailure => UploadOutcome::UnexpectedFailure,
        };
    }

    private function log(OrderInterface $order, UploadOutcome $outcome, ?ShipmondoFailure $failure = null): void
    {
        [$level, $message] = match ($outcome) {
            UploadOutcome::Uploaded => [LogLevel::INFO, 'The order {order} was uploaded to Shipmondo as the sales order {salesOrder}'],
            UploadOutcome::Rejected => [LogLevel::ERROR, 'Shipmondo rejected the upload of the order {order}, so the order is failed'],
            UploadOutcome::UnexpectedFailure => [LogLevel::ERROR, 'The upload of the order {order} failed unexpectedly, so the order is failed'],
            UploadOutcome::CredentialsFailure => [LogLevel::ERROR, 'Shipmondo did not accept the API credentials when uploading the order {order}, so the order goes back to pending'],
            UploadOutcome::RateLimited => [LogLevel::WARNING, 'Shipmondo rate limited the upload of the order {order}, so the order goes back to pending'],
            UploadOutcome::TemporaryFailure => [LogLevel::WARNING, 'The upload of the order {order} failed temporarily, so the order goes back to pending'],
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
