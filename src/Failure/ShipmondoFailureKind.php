<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Failure;

/**
 * The kinds of Shipmondo failure. Each caller decides what a kind means for it
 */
enum ShipmondoFailureKind: string
{
    /**
     * Shipmondo didn't accept the store's API credentials (401 or 403). It says nothing about the call
     */
    case CredentialsFailure = 'credentials_failure';

    /**
     * Shipmondo refused the call with 429 Too Many Requests. It says nothing about the call
     */
    case RateLimited = 'rate_limited';

    /**
     * A failure expected to pass: a Shipmondo server error or a network problem
     */
    case TemporaryFailure = 'temporary_failure';

    /**
     * Shipmondo has no such record (404)
     */
    case NotFound = 'not_found';

    /**
     * Shipmondo refused the call because of something about it, such as invalid data (any other 4xx)
     */
    case Rejected = 'rejected';

    /**
     * An answer nothing expects (an unreadable success, a redirect), or a failure before Shipmondo answered, such as
     * order data a data mapper can't turn into a sales order
     */
    case UnexpectedFailure = 'unexpected_failure';
}
