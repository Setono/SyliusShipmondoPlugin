<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Admin;

use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Client\Endpoint\Endpoint;
use Setono\Shipmondo\Response\PaymentGateways\PaymentGateway;
use Setono\Shipmondo\Response\ShipmentTemplates\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
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
        private readonly ShippingMethodRepositoryInterface $shippingMethodRepository,
        private readonly Client $client,
    ) {
    }

    public function index(Request $request): Response
    {
        /** @var list<PaymentMethodInterface> $paymentMethods */
        $paymentMethods = $this->paymentMethodRepository->findAll();

        /** @var list<ShippingMethodInterface> $shippingMethods */
        $shippingMethods = $this->shippingMethodRepository->findAll();

        /**
         * @var list<PaymentGateway> $shipmondoPaymentMethods
         */
        $shipmondoPaymentMethods = [];

        foreach (Endpoint::paginate($this->client->paymentGateways()->get(...)) as $collection) {
            foreach ($collection as $item) {
                $shipmondoPaymentMethods[] = $item;
            }
        }

        /**
         * @var list<ShipmentTemplate> $shipmondoShipmentTemplates
         */
        $shipmondoShipmentTemplates = [];

        foreach (Endpoint::paginate($this->client->shipmentTemplates()->get(...)) as $collection) {
            foreach ($collection as $item) {
                $shipmondoShipmentTemplates[] = $item;
            }
        }

        // todo this should be made using Symfony forms
        if ($request->isMethod('POST') && $request->request->has('payment_methods')) {
            /**
             * Example of posted array (the key is a Sylius payment method id, the value is a Shipmondo payment method id):
             *
             * [
             *   1 => "1",
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

                /** @var PaymentMethodInterface|null $shippingMethod */
                $shippingMethod = $this->paymentMethodRepository->find($paymentMethodId);
                if (null === $shippingMethod) {
                    continue;
                }

                Assert::isInstanceOf($shippingMethod, PaymentMethodInterface::class);
                $shippingMethod->setShipmondoId((int) $shipmondoPaymentMethodId);
                $this->paymentMethodRepository->add($shippingMethod);
            }
        }

        // todo this should be made using Symfony forms
        if ($request->isMethod('POST') && $request->request->has('shipping_methods')) {
            /**
             * Example of posted array (the key is a Sylius shipping method id, the value is an array of Shipmondo shipment template ids):
             *
             * [
             *   1 => [
             *     0 => "664116",
             *     1 => "664115"
             *   ],
             *   2 => [
             *     0 => "664114",
             *     1 => "664112"
             *   ]
             * ]
             *
             * @var array<int, list<string>> $postedShippingMethods
             */
            $postedShippingMethods = $request->request->all('shipping_methods');
            foreach ($postedShippingMethods as $shippingMethodId => $shipmondoShipmentTemplateIds) {
                /** @var ShippingMethodInterface|null $shippingMethod */
                $shippingMethod = $this->shippingMethodRepository->find($shippingMethodId);
                if (null === $shippingMethod) {
                    continue;
                }

                Assert::isInstanceOf($shippingMethod, ShippingMethodInterface::class);
                $shippingMethod->setAllowedShipmentTemplates($shipmondoShipmentTemplateIds);
                $this->shippingMethodRepository->add($shippingMethod);
            }
        }

        return $this->render('@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig', [
            'paymentMethods' => $paymentMethods,
            'shippingMethods' => $shippingMethods,
            'shipmondoPaymentMethods' => $shipmondoPaymentMethods,
            'shipmondoShipmentTemplates' => $shipmondoShipmentTemplates,
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
