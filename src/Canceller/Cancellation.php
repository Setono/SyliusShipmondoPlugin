<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Canceller;

/**
 * What happened when an order that is cancelled in Sylius was to become cancelled in its Shipmondo state too
 */
enum Cancellation
{
    /**
     * Shipmondo had no sales order for the order, so its Shipmondo state became cancelled
     */
    case Cancelled;

    /**
     * Its sales order was deleted, so its Shipmondo state became cancelled
     */
    case SalesOrderDeleted;

    /**
     * Its sales order couldn't be deleted, so the order stays uploaded
     */
    case SalesOrderNotDeleted;

    /**
     * Its sales order is deleted later: the DeleteOrder command was sent to an asynchronous transport, or waits for
     * the command being handled to finish
     */
    case SalesOrderDeletedLater;

    /**
     * The order is uploading, so its upload makes it cancelled once the upload is settled
     */
    case LeftToUpload;

    /**
     * The order isn't cancelled in Sylius, or a guard a store added holds it back, so nothing happened
     */
    case NotCancelled;
}
