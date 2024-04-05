<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

class RegisteredWebhooks implements RegisteredWebhooksInterface
{
    protected ?int $id = null;

    protected ?string $hash = null;

    protected ?\DateTimeInterface $registeredAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): void
    {
        $this->hash = $hash;
    }

    public function getRegisteredAt(): ?\DateTimeInterface
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(?\DateTimeInterface $registeredAt): void
    {
        $this->registeredAt = $registeredAt;
    }
}
