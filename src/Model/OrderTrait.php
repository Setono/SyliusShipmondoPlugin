<?php

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;

trait OrderTrait
{
    /**
     * todo create an index on this column if we need it for querying later on
     *
     * @ORM\Column(type="string")
     */
    protected string $shipmondoState = OrderInterface::SHIPMONDO_STATE_PENDING;

    public function getShipmondoState(): string
    {
        return $this->shipmondoState;
    }

    public function setShipmondoState(string $shipmondoState): void
    {
        $this->shipmondoState = $shipmondoState;
    }
}
