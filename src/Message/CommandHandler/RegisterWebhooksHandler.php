<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooksInterface;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;

final class RegisterWebhooksHandler
{
    public function __construct(
        private readonly RegisteredWebhooksRepositoryInterface $registeredWebhooksRepository,
        private readonly WebhookRegistrarInterface $webhookRegistrar,
        /** @var FactoryInterface<RegisteredWebhooksInterface> $registeredWebhooksFactory */
        private readonly FactoryInterface $registeredWebhooksFactory,
    ) {
    }

    public function __invoke(RegisterWebhooks $message): void
    {
        $this->webhookRegistrar->register();

        $hash = $this->webhookRegistrar->getVersion();

        $registeredWebhooks = $this->registeredWebhooksRepository->findOneByVersion($hash);
        if (null === $registeredWebhooks) {
            $registeredWebhooks = $this->registeredWebhooksFactory->createNew();
        }

        $registeredWebhooks->setRegisteredAt(new \DateTimeImmutable());
        $registeredWebhooks->setVersion($this->webhookRegistrar->getVersion());

        $this->registeredWebhooksRepository->add($registeredWebhooks);
    }
}
