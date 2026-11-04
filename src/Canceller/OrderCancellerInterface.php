<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Canceller;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface OrderCancellerInterface
{
    /**
     * Carries the cancellation of an order in Sylius over to Shipmondo. A pending or failed order is cancelled right
     * away. An uploaded order's sales order is deleted through the DeleteOrder command, which cancels the order once the
     * sales order is gone. An order in uploading is left to its upload, which cancels it when the upload is settled.
     *
     * Returns false when the sales order couldn't be deleted (the order stays uploaded), null when that is decided
     * later (DeleteOrder was sent to an asynchronous transport, or the order is uploading), and true otherwise
     */
    public function cancel(OrderInterface $order): ?bool;
}
