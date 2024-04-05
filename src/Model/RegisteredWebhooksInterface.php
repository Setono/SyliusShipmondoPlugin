<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Resource\Model\ResourceInterface;

interface RegisteredWebhooksInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getHash(): ?string;

    public function setHash(?string $hash): void;

    public function getRegisteredAt(): ?\DateTimeInterface;

    public function setRegisteredAt(\DateTimeInterface $registeredAt): void;
}
