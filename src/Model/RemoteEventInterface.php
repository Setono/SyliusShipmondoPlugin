<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Resource\Model\ResourceInterface;

/**
 * The remote event represents a Shipmondo remote event (i.e. webhook) being received
 */
interface RemoteEventInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getResource(): ?string;

    public function setResource(string $resource): void;

    public function getAction(): ?string;

    public function setAction(string $action): void;

    public function getPayload(): array;

    public function setPayload(array $payload): void;

    public function getCreatedAt(): \DateTimeInterface;
}
