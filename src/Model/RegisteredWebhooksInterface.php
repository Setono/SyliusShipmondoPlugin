<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Resource\Model\ResourceInterface;

interface RegisteredWebhooksInterface extends ResourceInterface
{
    public function getId(): ?int;

    public function getVersion(): ?string;

    public function setVersion(?string $version): void;

    public function getRegisteredAt(): ?\DateTimeInterface;

    public function setRegisteredAt(\DateTimeInterface $registeredAt): void;
}
