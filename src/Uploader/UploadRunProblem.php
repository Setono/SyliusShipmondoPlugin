<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

/**
 * A problem an upload run reports, because it most likely hits every order, not just one: it stopped the run early,
 * stopped the sales order lookups, or the run went on in spite of it. A single failed upload or lookup is no problem of
 * the run: it is logged, and shows on the order
 */
enum UploadRunProblem
{
    /**
     * Shipmondo didn't accept the store's API credentials for a sales order lookup or an upload (401 or 403), so the run
     * stopped
     */
    case CredentialsFailure;

    /**
     * Shipmondo rate limited an upload (429), so the run stopped
     */
    case UploadRateLimited;

    /**
     * Three sales order lookups or uploads in a row failed temporarily, with no call Shipmondo answered between them, so
     * the run stopped
     */
    case TemporaryFailuresInARow;

    /**
     * Three uploads in a row made the order failed (rejected, not found, or an unexpected failure), with no upload
     * Shipmondo accepted between them, so the run stopped
     */
    case FailedUploadsInARow;

    /**
     * Shipmondo rate limited a sales order lookup (429), so the lookups stopped, but the pending orders were uploaded
     */
    case LookupsRateLimited;

    /**
     * Three sales order lookups in a row failed because Shipmondo rejected them, or its answers couldn't be read (any
     * failure but a temporary one, a 429 or a credentials failure), with no lookup whose search succeeded between them.
     * So the lookups stopped, but the pending orders were uploaded
     */
    case FailedLookupsInARow;

    /**
     * Three uploads in a row ended in an unread success, with no uploaded order between them: Shipmondo created the
     * sales orders, but its answers couldn't be read or arrived too late, so the orders are unlinked until sales order
     * lookups link them. The run went on
     */
    case UnreadSuccessesInARow;
}
