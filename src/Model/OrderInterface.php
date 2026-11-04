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

    public const SHIPMONDO_STATE_FAILED = 'failed';

    public const SHIPMONDO_STATE_CANCELLED = 'cancelled';

    public function getShipmondoState(): string;

    /**
     * Called by the order workflow's marking store, with the context of the transition being applied. The Shipmondo
     * state owns the link to the sales order: entering uploaded links the order to the sales order whose id the
     * context holds under {@see \Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow::CONTEXT_SALES_ORDER_ID}, and
     * entering any other state unlinks it
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
}
