<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

class RegisteredWebhooks implements RegisteredWebhooksInterface
{
    protected ?int $id = null;

    protected ?string $endpoint = null;

    protected ?\DateTimeInterface $registeredAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEndpoint(): ?string
    {
        return $this->endpoint;
    }

    public function setEndpoint(?string $endpoint): void
    {
        $this->endpoint = $endpoint;
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
