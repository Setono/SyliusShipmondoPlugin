<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Admin;

use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Client\Endpoint\Endpoint;
use Setono\Shipmondo\Response\PaymentGateways\PaymentGateway;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

final class ShipmondoController extends AbstractController
{
    public function __construct(
        private readonly RegisteredWebhooksRepositoryInterface $registeredWebhooksRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly WebhookRegistrarInterface $webhookRegistrar,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly Client $client,
    ) {
    }

    public function index(Request $request): Response
    {
        /** @var list<PaymentMethodInterface> $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository->findAll();

        /**
         * @var list<PaymentGateway> $shipmondoPaymentMethods
         */
        $shipmondoPaymentMethods = [];

        foreach (Endpoint::paginate($this->client->paymentGateways()->get(...)) as $collection) {
            foreach ($collection as $item) {
                $shipmondoPaymentMethods[] = $item;
            }
        }

        // todo this should be made using Symfony forms
        if ($request->isMethod('POST') && $request->request->has('payment_methods')) {
            /**
             * Example of posted array (the key is Sylius payment method id, they value is Shipmondo payment method id):
             *
             * [
             *   1 => "1"
             *   2 => ""
             * ]
             *
             * @var array<int, string> $postedPaymentMethods
             */
            $postedPaymentMethods = $request->request->all('payment_methods');
            foreach ($postedPaymentMethods as $paymentMethodId => $shipmondoPaymentMethodId) {
                if ('' === $shipmondoPaymentMethodId) {
                    continue;
                }

                /** @var PaymentMethodInterface|null $paymentMethod */
                $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);
                if (null === $paymentMethod) {
                    continue;
                }

                Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
                $paymentMethod->setShipmondoId((int) $shipmondoPaymentMethodId);
                $this->paymentMethodRepository->add($paymentMethod);
            }
        }

        return $this->render('@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig', [
            'paymentMethods' => $paymentMethods,
            'shipmondoPaymentMethods' => $shipmondoPaymentMethods,
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
