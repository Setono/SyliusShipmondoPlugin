<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Doctrine\ORM\Mapping as ORM;

trait PaymentMethodTrait
{
    /** @ORM\Column(type="integer", nullable=true) */
    #[ORM\Column(type: 'integer', nullable: true)]
    protected ?int $shipmondoId = null;

    public function getShipmondoId(): ?int
    {
        return $this->shipmondoId;
    }

    public function setShipmondoId(?int $shipmondoId): void
    {
        $this->shipmondoId = $shipmondoId;
    }
}
