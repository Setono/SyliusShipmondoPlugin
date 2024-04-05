<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Admin;

use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;

final class ShipmondoController extends AbstractController
{
    public function __construct(
        private readonly RegisteredWebhooksRepositoryInterface $registeredWebhooksRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly WebhookRegistrarInterface $webhookRegistrar,
    ) {
    }

    public function index(): Response
    {
        return $this->render('@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig', [
            'registeredWebhooks' => $this->registeredWebhooksRepository->findOneByVersion($this->webhookRegistrar->getVersion()),
        ]);
    }

    public function registerWebhooks(): RedirectResponse
    {
        $this->commandBus->dispatch(new RegisterWebhooks());

        $this->addFlash('success', 'Webhooks registered successfully');

        return $this->redirectToRoute('setono_sylius_shipmondo_admin_shipmondo_index');
    }
}
