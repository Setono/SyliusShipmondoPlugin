<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Canceller;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

interface OrderCancellerInterface
{
    /**
     * Makes an order that is cancelled in Sylius cancelled in its Shipmondo state too. A pending or failed order becomes
     * cancelled right away. An uploaded order's sales order is deleted through the DeleteOrder command, which makes the
     * order cancelled once the sales order is gone; the command is dispatched once the command being handled (if any)
     * has finished, so a failure to send it can't undo that command. An order in uploading is left to its upload, which
     * makes it cancelled once the upload is settled.
     */
    public function cancel(OrderInterface $order): Cancellation;
}
