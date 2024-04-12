<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;

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

    public function setShipmondoState(string $shipmondoState): void
    {
        $this->shipmondoState = $shipmondoState;
    }

    public function getShipmondoId(): ?int
    {
        return $this->shipmondoId;
    }

    public function setShipmondoId(?int $shipmondoId): void
    {
        $this->shipmondoId = $shipmondoId;
    }
}
