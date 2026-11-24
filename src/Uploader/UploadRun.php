<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

use Psr\Log\LoggerInterface;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * The stop rules of one upload run. It is told how each sales order lookup and each upload the run sees went, keeps
 * count of what happened in a row, and decides when the run, or its lookups, stop, and which problems the run reports.
 * It sees only the uploads handled synchronously: how an upload sent to an asynchronous transport goes is unknown to it
 *
 * @internal
 */
final class UploadRun
{
    /**
     * This many temporary failures, failed uploads, failed lookups or unread successes in a row most likely hit every
     * order, not just these
     */
    private const MAX_IN_A_ROW = 3;

    private int $temporaryFailuresInARow = 0;

    private int $failedUploadsInARow = 0;

    private int $failedLookupsInARow = 0;

    private int $unreadSuccessesInARow = 0;

    private bool $stopped = false;

    private bool $lookupsStopped = false;

    /** @var list<UploadRunProblem> */
    private array $problems = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param ShipmondoFailure|null $failure why Shipmondo couldn't be searched for the order's sales order, or null when
     *                                       it was, whatever it found
     */
    public function recordLookup(OrderInterface $order, ?ShipmondoFailure $failure): void
    {
        $kind = $failure?->kind;

        $this->temporaryFailuresInARow = ShipmondoFailureKind::TemporaryFailure === $kind ? $this->temporaryFailuresInARow + 1 : 0;

        $this->failedLookupsInARow = match ($kind) {
            null => 0,
            ShipmondoFailureKind::TemporaryFailure => $this->failedLookupsInARow,
            // a credentials failure or a 429 stops the lookups anyway
            default => $this->failedLookupsInARow + 1,
        };

        if (ShipmondoFailureKind::CredentialsFailure === $kind) {
            // the uploads would fail the same way
            $this->stop(
                UploadRunProblem::CredentialsFailure,
                'The upload run stopped, because Shipmondo answered the sales order lookup of the order {order} with a {outcome}. The remaining orders wait for the next run',
                ['order' => $order->getNumber(), 'outcome' => $kind->value],
            );
        } elseif (ShipmondoFailureKind::RateLimited === $kind) {
            // the lookups have their own rate limit in Shipmondo, so it doesn't keep the pending orders from being uploaded
            $this->stopLookups(
                UploadRunProblem::LookupsRateLimited,
                'The sales order lookups stopped, because Shipmondo answered the lookup of the order {order} with a {outcome}. The remaining unlinked orders wait for the next run, and the pending orders are uploaded',
                ['order' => $order->getNumber(), 'outcome' => $kind->value],
            );
        } elseif (self::MAX_IN_A_ROW === $this->temporaryFailuresInARow) {
            $this->stopOnTemporaryFailures();
        } elseif (self::MAX_IN_A_ROW === $this->failedLookupsInARow) {
            $this->stopLookups(
                UploadRunProblem::FailedLookupsInARow,
                'The sales order lookups stopped after {count} lookups in a row that failed, which suggests a problem with every lookup, such as Shipmondo rejecting the search, or answering in a way the SDK can\'t read. The remaining unlinked orders wait for the next run, and the pending orders are uploaded',
                ['count' => $this->failedLookupsInARow],
            );
        }
    }

    public function recordUpload(OrderInterface $order, UploadOutcome $outcome): void
    {
        $this->temporaryFailuresInARow = match ($outcome) {
            UploadOutcome::TemporaryFailure => $this->temporaryFailuresInARow + 1,
            // no call was made
            UploadOutcome::ChangedWhileUploading, UploadOutcome::Skipped => $this->temporaryFailuresInARow,
            default => 0,
        };

        $this->failedUploadsInARow = match ($outcome) {
            // Shipmondo accepted the order, even when its answer couldn't be read, so nothing shop-wide is wrong
            UploadOutcome::Uploaded, UploadOutcome::UnreadSuccess => 0,
            UploadOutcome::Rejected, UploadOutcome::UnexpectedFailure => $this->failedUploadsInARow + 1,
            default => $this->failedUploadsInARow,
        };

        $this->unreadSuccessesInARow = match ($outcome) {
            UploadOutcome::UnreadSuccess => $this->unreadSuccessesInARow + 1,
            // only an answer that could be read shows that Shipmondo's answers can be read
            UploadOutcome::Uploaded => 0,
            default => $this->unreadSuccessesInARow,
        };

        if (UploadOutcome::CredentialsFailure === $outcome || UploadOutcome::RateLimited === $outcome) {
            $this->stop(
                UploadOutcome::CredentialsFailure === $outcome ? UploadRunProblem::CredentialsFailure : UploadRunProblem::UploadRateLimited,
                'The upload run stopped, because Shipmondo answered the upload of the order {order} with a {outcome}. The remaining orders stay pending for the next run',
                ['order' => $order->getNumber(), 'outcome' => $outcome->value],
            );
        } elseif (self::MAX_IN_A_ROW === $this->temporaryFailuresInARow) {
            $this->stopOnTemporaryFailures();
        } elseif (self::MAX_IN_A_ROW === $this->failedUploadsInARow) {
            // Shipmondo answers 422 whether the order or a shop-wide setting (e.g. a deleted payment gateway) is the problem
            $this->stop(
                UploadRunProblem::FailedUploadsInARow,
                'The upload run stopped after {count} uploads in a row that made the order failed, which suggests a shop-wide problem, such as a payment gateway or shipment template that no longer exists in Shipmondo, or a data mapper that fails for every order. The remaining orders stay pending for the next run',
                ['count' => $this->failedUploadsInARow],
            );
        } elseif (UploadOutcome::UnreadSuccess === $outcome && self::MAX_IN_A_ROW === $this->unreadSuccessesInARow) {
            // Shipmondo created the sales orders, and sales order lookups link the orders to them
            $this->report(
                UploadRunProblem::UnreadSuccessesInARow,
                'Shipmondo answered {count} uploads in a row with a success that was cut off or could not be read, which suggests a problem with every upload, such as time limits too tight for Shipmondo, or a change to its answers the SDK can\'t read. The upload run goes on, since Shipmondo created the sales orders, and sales order lookups link the orders to them',
                ['count' => $this->unreadSuccessesInARow],
            );
        }
    }

    /**
     * Whether the run stopped early: it makes no more sales order lookups or uploads
     */
    public function stopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Whether the run makes no more sales order lookups, because the lookups stopped, or the whole run did
     */
    public function lookupsStopped(): bool
    {
        return $this->lookupsStopped || $this->stopped;
    }

    /**
     * @return list<UploadRunProblem> the problems the run reports, in the order they arose
     */
    public function problems(): array
    {
        return $this->problems;
    }

    private function stopOnTemporaryFailures(): void
    {
        $this->stop(
            UploadRunProblem::TemporaryFailuresInARow,
            'The upload run stopped after {count} temporary failures in a row, sales order lookups and uploads alike, which suggests that Shipmondo is down or too slow to answer in time. The remaining orders wait for the next run',
            ['count' => $this->temporaryFailuresInARow],
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stop(UploadRunProblem $problem, string $message, array $context): void
    {
        $this->stopped = true;
        $this->report($problem, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function stopLookups(UploadRunProblem $problem, string $message, array $context): void
    {
        $this->lookupsStopped = true;
        $this->report($problem, $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function report(UploadRunProblem $problem, string $message, array $context): void
    {
        $this->logger->error($message, $context);

        if (!in_array($problem, $this->problems, true)) {
            $this->problems[] = $problem;
        }
    }
}
