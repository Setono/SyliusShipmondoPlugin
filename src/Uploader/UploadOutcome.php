<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

/**
 * What came of uploading one order, and so which Shipmondo state the order ended in
 */
enum UploadOutcome: string
{
    /**
     * Shipmondo created the sales order. The order is uploaded and linked to it
     */
    case Uploaded = 'uploaded';

    /**
     * Shipmondo answered the upload with a success, but its answer was cut off or couldn't be read: it created the sales
     * order, but which is unknown. The order is unlinked, until a sales order lookup settles it
     */
    case UnreadSuccess = 'unread_success';

    /**
     * Shipmondo refused the upload because of something about this order, e.g. invalid data (a 4xx other than
     * 401, 403 and 429). The order is failed
     */
    case Rejected = 'rejected';

    /**
     * The upload failed before Shipmondo answered, or in a way nothing expects, e.g. a data mapper couldn't turn the
     * order into a sales order. The order is failed
     */
    case UnexpectedFailure = 'unexpected_failure';

    /**
     * Shipmondo didn't accept the store's API credentials (401 or 403). The order goes back to pending
     */
    case CredentialsFailure = 'credentials_failure';

    /**
     * Shipmondo answered 429 Too Many Requests. The order goes back to pending
     */
    case RateLimited = 'rate_limited';

    /**
     * A failure expected to pass, such as a Shipmondo server error or a network problem. When the call that creates the
     * sales order failed so, Shipmondo may have created it before the failure, so the order is unlinked, until a sales
     * order lookup settles it. When a read before it failed, e.g. of the payment gateways, the order goes back to pending
     */
    case TemporaryFailure = 'temporary_failure';

    /**
     * The order changed while it was uploading, before its upload command was handled, so it wasn't uploaded. It goes back to pending
     */
    case ChangedWhileUploading = 'changed_while_uploading';

    /**
     * The order wasn't waiting for an upload (its Shipmondo state isn't uploading), so nothing happened
     */
    case Skipped = 'skipped';
}
