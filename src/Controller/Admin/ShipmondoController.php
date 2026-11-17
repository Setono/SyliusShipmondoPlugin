<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Admin;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\Shipmondo\Response\PaymentGateway\PaymentGateway;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
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
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
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
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
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

        /**
         * @var list<ShipmentTemplate> $shipmondoShipmentTemplates
         */
        $shipmondoShipmentTemplates = [];

        // The page is still rendered when Shipmondo fails (e.g. so the webhooks can be registered), with a flash message
        // that explains why. Anything else is a bug, and is left to fail loudly
        try {
            foreach ($this->client->paymentGateways()->paginate() as $item) {
                $shipmondoPaymentMethods[] = $item;
            }

            foreach ($this->client->shipmentTemplates()->paginate() as $item) {
                $shipmondoShipmentTemplates[] = $item;
            }
        } catch (ShipmondoException $e) {
            $failure = ShipmondoFailure::of($e);

            $this->logger->warning('Could not fetch the payment gateways and shipment templates from Shipmondo for the Shipmondo page', $failure->logContext());
            $this->addFailureFlash('setono_sylius_shipmondo.shipmondo_data_not_fetched', $failure);
        }

        // todo this should be made using Symfony forms
        if ($request->isMethod('POST') && $request->request->has('payment_methods')) {
            /**
             * Example of posted array (the key is a Sylius payment method id, the value is a Shipmondo payment method id,
             * or empty for none):
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
                $paymentMethod = $this->paymentMethodRepository->find($paymentMethodId);
                if (null === $paymentMethod) {
                    continue;
                }

                Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
                // the empty choice removes the mapping
                $paymentMethod->setShipmondoId('' === $shipmondoPaymentMethodId ? null : (int) $shipmondoPaymentMethodId);
                $this->paymentMethodRepository->add($paymentMethod);
            }
        }

        // todo this should be made using Symfony forms
        if ($request->isMethod('POST') && ($request->request->has('listed_shipping_methods') || $request->request->has('shipping_methods'))) {
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

            /**
             * A select without a selected shipment template isn't submitted, so the form also posts the ids of the shipping
             * methods it lists. A template override without them can still map shipment templates, but not remove them all
             *
             * @var list<string> $listedShippingMethodIds
             */
            $listedShippingMethodIds = $request->request->all('listed_shipping_methods');

            foreach (array_unique([...$listedShippingMethodIds, ...array_keys($postedShippingMethods)]) as $shippingMethodId) {
                $shippingMethod = $this->shippingMethodRepository->find($shippingMethodId);
                if (null === $shippingMethod) {
                    continue;
                }

                Assert::isInstanceOf($shippingMethod, ShippingMethodInterface::class);
                $shippingMethod->setAllowedShipmentTemplates($postedShippingMethods[$shippingMethodId] ?? []);
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
        try {
            $this->commandBus->dispatch(new RegisterWebhooks());

            $this->addFlash('success', 'setono_sylius_shipmondo.webhooks_registered');
        } catch (HandlerFailedException $e) {
            $failure = ShipmondoFailure::of(array_values($e->getWrappedExceptions(ShipmondoException::class))[0] ?? throw $e);

            $this->logger->error('The webhooks could not be registered in Shipmondo', $failure->logContext());
            $this->addFailureFlash('setono_sylius_shipmondo.webhooks_not_registered', $failure);
        }

        return $this->redirectToRoute('setono_sylius_shipmondo_admin_shipmondo_index');
    }

    /**
     * Flashes an error that says what failed, followed by the failure's explanation as its %reason%
     */
    private function addFailureFlash(string $message, ShipmondoFailure $failure): void
    {
        $this->addFlash('error', [
            'message' => $message,
            'parameters' => ['%reason%' => $failure->trans($this->translator)],
        ]);
    }
}
