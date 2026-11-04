<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Setono\Doctrine\ORMTrait;
use Setono\Shipmondo\Client\ClientInterface;
use Setono\Shipmondo\Exception\ResponseAwareException;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
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
            return $this->settle($order, self::classify($e), failure: $e);
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
        $result = $this->commandBus->dispatch(new UploadOrder($order))->last(HandledStamp::class)?->getResult();

        return $result instanceof UploadOutcome ? $result : null;
    }

    /**
     * Records how the upload went on the order: links it to its sales order (or to none) and moves its Shipmondo state.
     *
     * Something may have changed the order while Shipmondo was called, e.g. its payment was captured. Flushing the stale
     * order would then fail and close the entity manager, leaving the order in uploading and the sales order Shipmondo
     * just created unlinked. So the order's row is locked and read again first.
     */
    private function settle(
        OrderInterface $order,
        UploadOutcome $outcome,
        ?int $salesOrderId = null,
        ?\Throwable $failure = null,
    ): UploadOutcome {
        $manager = $this->getManager($order);

        $manager->wrapInTransaction(function () use ($manager, $order, $outcome, $salesOrderId): void {
            $manager->refresh($order, LockMode::PESSIMISTIC_WRITE);

            $order->setShipmondoId($salesOrderId);
            $this->orderWorkflow->apply($order, match ($outcome) {
                UploadOutcome::Uploaded => OrderWorkflow::TRANSITION_COMPLETE_UPLOAD,
                UploadOutcome::Rejected, UploadOutcome::UnexpectedFailure => OrderWorkflow::TRANSITION_FAIL,
                default => OrderWorkflow::TRANSITION_RETRY,
            });
        });

        $this->log($order, $outcome, $failure);

        return $outcome;
    }

    private static function classify(\Throwable $failure): UploadOutcome
    {
        // PSR-18: Shipmondo couldn't be reached, e.g. a network problem or a timeout
        if ($failure instanceof ClientExceptionInterface) {
            return UploadOutcome::TemporaryFailure;
        }

        // not Shipmondo's answer
        if (!$failure instanceof ResponseAwareException) {
            return UploadOutcome::UnexpectedFailure;
        }

        $statusCode = $failure->getResponse()->getStatusCode();

        return match (true) {
            401 === $statusCode, 403 === $statusCode => UploadOutcome::CredentialsFailure,
            429 === $statusCode => UploadOutcome::RateLimited,
            $statusCode >= 500 => UploadOutcome::TemporaryFailure,
            $statusCode >= 400 => UploadOutcome::Rejected,
            // not an error status: a successful answer the SDK couldn't read, so the sales order may exist, or a redirect
            // the HTTP client didn't follow
            default => UploadOutcome::UnexpectedFailure,
        };
    }

    private function log(OrderInterface $order, UploadOutcome $outcome, ?\Throwable $failure = null): void
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
            $context['exception'] = $failure;

            if ($failure instanceof ResponseAwareException) {
                $context['shipmondoStatusCode'] = $failure->getResponse()->getStatusCode();
                $context['shipmondoError'] = $failure->getError();
            }
        }

        $this->logger->log($level, $message, $context);
    }
}
