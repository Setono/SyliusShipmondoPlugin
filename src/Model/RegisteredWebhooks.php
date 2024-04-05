<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

class RegisteredWebhooks implements RegisteredWebhooksInterface
{
    protected ?int $id = null;

    protected ?string $version = null;

    protected ?\DateTimeInterface $registeredAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function setVersion(?string $version): void
    {
        $this->version = $version;
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
