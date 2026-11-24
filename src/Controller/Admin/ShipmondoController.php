<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller\Admin;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\Shipmondo\Response\PaymentGateway\PaymentGateway;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Setono\SyliusShipmondoPlugin\Provider\SetupProviderInterface;
use Setono\SyliusShipmondoPlugin\Registrar\UnreachableWebhookEndpointException;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

final class ShipmondoController extends AbstractController
{
    public function __construct(
        private readonly RegisteredWebhooksRepositoryInterface $registeredWebhooksRepository,
        private readonly WebhookRegistrarInterface $webhookRegistrar,
        private readonly PaymentMethodRepositoryInterface $paymentMethodRepository,
        private readonly ShippingMethodRepositoryInterface $shippingMethodRepository,
        private readonly SetupProviderInterface $setupProvider,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly bool $registerWebhooksAutomatically,
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

        // Each list is fetched on its own, so a list that couldn't be fetched doesn't keep the other one from being
        // mapped, and its table can say it couldn't be loaded instead of that Shipmondo has none. The page is still
        // rendered when Shipmondo fails (e.g. so the webhooks can be registered), with a flash message that says what
        // couldn't be fetched and why. Anything else is a bug, and is left to fail loudly
        $paymentGatewaysFailure = null;

        try {
            $shipmondoPaymentMethods = $this->setupProvider->paymentGateways();
        } catch (ShipmondoException $e) {
            $paymentGatewaysFailure = ShipmondoFailure::of($e);

            $this->logger->warning('Could not fetch the payment gateways from Shipmondo for the Shipmondo page', $paymentGatewaysFailure->logContext());
        }

        $shipmentTemplatesFailure = null;

        try {
            $shipmondoShipmentTemplates = $this->setupProvider->shipmentTemplates();
        } catch (ShipmondoException $e) {
            $shipmentTemplatesFailure = ShipmondoFailure::of($e);

            $this->logger->warning('Could not fetch the shipment templates from Shipmondo for the Shipmondo page', $shipmentTemplatesFailure->logContext());
        }

        // this shop's webhooks are compared at the endpoint the upload run keeps them at, so only once an admin has
        // registered them there
        $registeredWebhooks = $this->registeredWebhooksRepository->findLatest();
        $webhooksEndpoint = $registeredWebhooks?->getEndpoint();
        $webhookComparison = null;
        $webhooksFailure = null;

        if (null !== $webhooksEndpoint) {
            try {
                $webhookComparison = $this->webhookRegistrar->compare($webhooksEndpoint);
            } catch (ShipmondoException $e) {
                $webhooksFailure = ShipmondoFailure::of($e);

                $this->logger->warning('Could not fetch this shop\'s webhooks from Shipmondo for the Shipmondo page', $webhooksFailure->logContext());
            }
        }

        $notFetched = [
            'setono_sylius_shipmondo.payment_gateways_not_fetched' => $paymentGatewaysFailure,
            'setono_sylius_shipmondo.shipment_templates_not_fetched' => $shipmentTemplatesFailure,
        ];
        if (null === $webhooksEndpoint) {
            $this->addNotFetchedFlashes($notFetched, 'setono_sylius_shipmondo.shipmondo_data_not_fetched');
        } else {
            $this->addNotFetchedFlashes($notFetched + ['setono_sylius_shipmondo.webhooks_not_fetched' => $webhooksFailure], 'setono_sylius_shipmondo.shipmondo_data_and_webhooks_not_fetched');
        }

        // Whether the page knows which payment gateways and shipment templates Shipmondo has, and so which mappings are
        // stale. A list that was fetched is shown with its stale mappings, or saving its form would remove them
        $paymentGatewaysFetched = null === $paymentGatewaysFailure;
        $shipmentTemplatesFetched = null === $shipmentTemplatesFailure;

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

        /**
         * The payment methods mapped to a payment gateway that no longer exists in Shipmondo, with that payment gateway
         *
         * @var \SplObjectStorage<PaymentMethodInterface, int> $stalePaymentGateways
         */
        $stalePaymentGateways = new \SplObjectStorage();

        /**
         * The shipping methods that allow shipment templates that no longer exist in Shipmondo, with those shipment templates
         *
         * @var \SplObjectStorage<ShippingMethodInterface, non-empty-list<int>> $staleShipmentTemplates
         */
        $staleShipmentTemplates = new \SplObjectStorage();

        // read after saving, so they show the mappings as saved
        if ($paymentGatewaysFetched) {
            foreach ($paymentMethods as $paymentMethod) {
                $stalePaymentGateway = $this->setupProvider->stalePaymentGateway($paymentMethod);
                if (null !== $stalePaymentGateway) {
                    $stalePaymentGateways[$paymentMethod] = $stalePaymentGateway;
                }
            }
        }

        if ($shipmentTemplatesFetched) {
            foreach ($shippingMethods as $shippingMethod) {
                $stale = $this->setupProvider->staleShipmentTemplates($shippingMethod);
                if ([] !== $stale) {
                    $staleShipmentTemplates[$shippingMethod] = $stale;
                }
            }
        }

        return $this->render('@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig', [
            'paymentMethods' => $paymentMethods,
            'shippingMethods' => $shippingMethods,
            'shipmondoPaymentMethods' => $shipmondoPaymentMethods,
            'shipmondoShipmentTemplates' => $shipmondoShipmentTemplates,
            'paymentGatewaysFetched' => $paymentGatewaysFetched,
            'shipmentTemplatesFetched' => $shipmentTemplatesFetched,
            'stalePaymentGateways' => $stalePaymentGateways,
            'staleShipmentTemplates' => $staleShipmentTemplates,
            'registeredWebhooks' => $registeredWebhooks,
            // null when no endpoint is recorded, or this shop's webhooks couldn't be fetched
            'webhookComparison' => $webhookComparison,
            'registerWebhooksAutomatically' => $this->registerWebhooksAutomatically,
        ]);
    }

    /**
     * Registers the webhooks right away, whatever the app's Messenger routing, so the flash says how it went. The
     * webhooks' endpoint is at the host the admin is opened at, so the registrar refuses it when Shipmondo can't reach
     * that host, e.g. localhost on a developer's machine
     */
    public function registerWebhooks(): RedirectResponse
    {
        try {
            $this->webhookRegistrar->register();

            $this->addFlash('success', 'setono_sylius_shipmondo.webhooks_registered');
        } catch (UnreachableWebhookEndpointException $e) {
            $this->addFlash('error', [
                'message' => 'setono_sylius_shipmondo.webhooks_not_registered_at_unreachable_endpoint',
                'parameters' => ['%endpoint%' => $e->endpoint],
            ]);
        } catch (ShipmondoException $e) {
            $failure = ShipmondoFailure::of($e);

            $this->logger->error('The webhooks could not be registered in Shipmondo', $failure->logContext());
            $this->addFailureFlash('setono_sylius_shipmondo.webhooks_not_registered', $failure->trans($this->translator));
        }

        return $this->redirectToRoute('setono_sylius_shipmondo_admin_shipmondo_index');
    }

    /**
     * Flashes which lists couldn't be fetched from Shipmondo, and why: once for every list when each list the page
     * fetched failed for the same reason, e.g. a credentials failure, and once per list otherwise
     *
     * @param array<string, ShipmondoFailure|null> $failures each list the page fetched, by the message that says it
     *                                                       couldn't be fetched, with why it couldn't, or null when it was
     * @param string $allNotFetched the message that says none of them could be fetched
     */
    private function addNotFetchedFlashes(array $failures, string $allNotFetched): void
    {
        $reasons = [];
        foreach ($failures as $message => $failure) {
            if (null !== $failure) {
                $reasons[$message] = $failure->trans($this->translator);
            }
        }

        $distinctReasons = array_values(array_unique($reasons));
        if (count($reasons) === count($failures) && 1 === count($distinctReasons)) {
            $this->addFailureFlash($allNotFetched, $distinctReasons[0]);

            return;
        }

        foreach ($reasons as $message => $reason) {
            $this->addFailureFlash($message, $reason);
        }
    }

    /**
     * Flashes an error that says what failed, followed by the failure's explanation as its %reason%
     */
    private function addFailureFlash(string $message, string $reason): void
    {
        $this->addFlash('error', [
            'message' => $message,
            'parameters' => ['%reason%' => $reason],
        ]);
    }
}
