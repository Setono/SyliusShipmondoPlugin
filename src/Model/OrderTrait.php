<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;

trait OrderTrait
{
    /**
     * @ORM\Version()
     *
     * @ORM\Column(type="integer")
     */
    #[ORM\Version]
    #[ORM\Column(type: 'integer')]
    protected int $version = 1;

    /**
     * todo create an index on this column if we need it for querying later on
     *
     * @ORM\Column(type="string")
     */
    #[ORM\Column(type: 'string')]
    protected string $shipmondoState = OrderInterface::SHIPMONDO_STATE_PENDING;

    /** @ORM\Column(type="integer", nullable=true) */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $shipmondoId = null;

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(?int $version): void
    {
        $this->version = (int) $version;
    }

    public function getShipmondoState(): string
    {
        return $this->shipmondoState;
    }

    public function setShipmondoState(string $shipmondoState, array $context = []): void
    {
        $shipmondoId = null;

        if (OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO === $shipmondoState) {
            $shipmondoId = $context[OrderWorkflow::CONTEXT_SALES_ORDER_ID] ?? null;

            if (!is_int($shipmondoId)) {
                throw new \InvalidArgumentException(sprintf(
                    'An uploaded order is linked to a sales order, so the order workflow\'s context must hold the sales order id under "%s"',
                    OrderWorkflow::CONTEXT_SALES_ORDER_ID,
                ));
            }
        }

        $this->shipmondoState = $shipmondoState;
        $this->shipmondoId = $shipmondoId;
    }

    public function getShipmondoId(): ?int
    {
        return $this->shipmondoId;
    }
}
