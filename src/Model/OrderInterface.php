<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Core\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Resource\Model\VersionedInterface;

interface OrderInterface extends BaseOrderInterface, VersionedInterface
{
    public const SHIPMONDO_STATE_PENDING = 'pending';

    public const SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO = 'uploading_to_shipmondo';

    public const SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO = 'uploaded_to_shipmondo';

    /**
     * Shipmondo may have a sales order for the order, but the order isn't linked to it: Shipmondo answered the call that
     * creates it with a success that was cut off or couldn't be read, or the call failed temporarily, so whether, and
     * which, sales order it created is unknown. The upload run's sales order lookup settles it
     */
    public const SHIPMONDO_STATE_UNLINKED = 'unlinked';

    public const SHIPMONDO_STATE_FAILED = 'failed';

    public const SHIPMONDO_STATE_CANCELLED = 'cancelled';

    public function getShipmondoState(): string;

    /**
     * Called by the order workflow's marking store, with the context of the transition being applied. The Shipmondo
     * state owns the link to the sales order: entering uploaded links the order to the sales order whose id the
     * context holds under {@see \Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow::CONTEXT_SALES_ORDER_ID}, and
     * entering any other state unlinks it. It also records when the Shipmondo state changed
     * ({@see getShipmondoStateChangedAt()})
     *
     * @param array<array-key, mixed> $context
     *
     * @throws \InvalidArgumentException if the order enters uploaded without a sales order id in the context
     */
    public function setShipmondoState(string $shipmondoState, array $context = []): void;

    /**
     * Returns the id of the sales order the order is linked to. Only an uploaded order is linked to a sales order
     */
    public function getShipmondoId(): ?int;

    /**
     * Returns when the Shipmondo state last changed, i.e. when the order entered the state it is in: the time of the
     * order workflow's latest transition. It is null until the Shipmondo state first changes: for a new order that is
     * still pending, and for an order whose Shipmondo state hasn't changed since the plugin started recording it
     */
    public function getShipmondoStateChangedAt(): ?\DateTimeImmutable;
}
