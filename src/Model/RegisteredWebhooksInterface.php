<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Resource\Model\ResourceInterface;

/**
 * A registration of the webhooks: the endpoint Shipmondo sends them to, and when they were last registered there. The
 * upload run registers the webhooks again at the endpoint of the latest registration
 */
interface RegisteredWebhooksInterface extends ResourceInterface
{
    public function getId(): ?int;

    /**
     * Where Shipmondo sends the webhooks, e.g. https://shop.example.com/webhook/shipmondo. Null for a registration
     * recorded before the plugin recorded the endpoint
     */
    public function getEndpoint(): ?string;

    public function setEndpoint(?string $endpoint): void;

    public function getRegisteredAt(): ?\DateTimeInterface;

    public function setRegisteredAt(\DateTimeInterface $registeredAt): void;
}
