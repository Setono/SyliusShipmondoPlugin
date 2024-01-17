<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

class RemoteEvent implements RemoteEventInterface
{
    protected ?int $id = null;

    protected ?string $resource = null;

    protected ?string $action = null;

    protected ?array $payload = null;

    protected readonly \DateTimeInterface $createdAt;

    final public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getResource(): ?string
    {
        return $this->resource;
    }

    public function setResource(string $resource): void
    {
        $this->resource = $resource;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    public function getPayload(): array
    {
        return $this->payload ?? [];
    }

    public function setPayload(array $payload): void
    {
        $this->payload = $payload;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }
}
