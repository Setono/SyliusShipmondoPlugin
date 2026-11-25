<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

/**
 * Uploads Sylius orders to Shipmondo as sales orders, and keeps each order's Shipmondo state in step with how its
 * upload went
 */
interface OrderUploaderInterface
{
    /**
     * Runs an upload run. First it looks up the sales order of each order that has been unlinked for at least ten
     * minutes, by the exact order id its upload sent, and settles the order by what it finds: one sales order links the
     * order to it, none puts it back to pending, and several leave it unlinked. The lookups run here, never through the
     * command bus. Then it puts each pending order that qualifies for upload in uploading, and uploads it through the
     * UploadOrder command.
     *
     * Stop rules end the run early when the remaining orders would most likely fail the same way, and they wait for the
     * next run. They apply to the lookups and to the uploads the run sees, which are those handled synchronously (the
     * default): how an upload sent to an asynchronous transport goes is unknown to the run.
     *
     * - A credentials failure stops the run, in a lookup or an upload, and so does a 429 in an upload.
     * - Three temporary failures in a row, lookups and uploads alike, stop the run. Any lookup or upload Shipmondo
     *   answered starts the count over; an upload that made no call leaves it as it is.
     * - Three uploads in a row that make the order failed (rejected, not found, or an unexpected failure) stop the run.
     *   An upload Shipmondo accepted (uploaded, or an unread success) starts the count over.
     * - A 429 in a lookup stops the lookups, and so do three lookups in a row that fail in any other way than
     *   temporarily (rejected, not found, or an unexpected failure). A lookup whose search succeeded starts the count
     *   over. The pending orders are still uploaded.
     * - Three unread successes in a row don't stop the run, since Shipmondo created the sales orders, and lookups link
     *   them later, but the run reports them. Only an uploaded order starts the count over.
     *
     * Each stop, and each problem the run reports, is logged as an error.
     *
     * @return list<UploadRunProblem> the problems the run reports, in the order they arose, or an empty list when it
     *                                got through every order without one
     */
    public function upload(): array;

    /**
     * Uploads an order whose Shipmondo state is uploading, and settles that state: uploaded (and linked to its sales
     * order), unlinked, failed, or back to pending for the next upload run. The returned outcome says which, and why. A
     * failed upload is logged, never thrown.
     *
     * An order in any other Shipmondo state is left alone ({@see UploadOutcome::Skipped}).
     *
     * @param int|null $version the order's version when its upload started. When the order has another version
     *                          now, it changed since, so it goes back to pending without being uploaded
     */
    public function uploadOrder(OrderInterface $order, ?int $version = null): UploadOutcome;

    /**
     * Retries the upload of a failed order: puts it back to pending, so the next upload run uploads it if it still
     * qualifies for upload. Shipmondo has no sales order for a failed order, so it can't be uploaded twice. Nothing is
     * asked of Shipmondo.
     *
     * The order is read again first, and an order that is no longer failed by then, e.g. because it was cancelled in the
     * meantime, is left as it is. So is an order in any other Shipmondo state, also an uploading one, which is left to
     * its upload.
     *
     * @return bool whether the order was retried, and so is pending now
     */
    public function retry(OrderInterface $order): bool;
}
