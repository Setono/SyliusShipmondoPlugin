<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Log\LogLevel;
use Setono\Shipmondo\Exception\InternalServerErrorException;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Setono\Shipmondo\Response\PaymentGateway\PaymentGateway;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Controller\Admin\ShipmondoController;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
use Setono\SyliusShipmondoPlugin\Provider\SetupProvider;
use Setono\SyliusShipmondoPlugin\Registrar\UnreachableWebhookEndpointException;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookComparison;
use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Setono\SyliusShipmondoPlugin\Repository\RegisteredWebhooksRepositoryInterface;
use Sylius\Component\Core\Model\PaymentMethod as BasePaymentMethod;
use Sylius\Component\Core\Model\ShippingMethod as BaseShippingMethod;
use Sylius\Component\Core\Repository\PaymentMethodRepositoryInterface;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\PaymentMethod;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;
use Tests\Setono\SyliusShipmondoPlugin\Unit\RecordingLogger;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Translations;
use Twig\Environment;

final class ShipmondoControllerTest extends TestCase
{
    use ProphecyTrait;

    private const TEMPLATE = '@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig';

    private const ENDPOINT = 'https://shop.example.com/webhook/shipmondo';

    /** @var ObjectProphecy<RegisteredWebhooksRepositoryInterface> */
    private ObjectProphecy $registeredWebhooksRepository;

    /** @var ObjectProphecy<WebhookRegistrarInterface> */
    private ObjectProphecy $webhookRegistrar;

    /** @var ObjectProphecy<PaymentMethodRepositoryInterface> */
    private ObjectProphecy $paymentMethodRepository;

    /** @var ObjectProphecy<ShippingMethodRepositoryInterface> */
    private ObjectProphecy $shippingMethodRepository;

    /** @var ObjectProphecy<HttpClientInterface> */
    private ObjectProphecy $httpClient;

    /** @var ObjectProphecy<Environment> */
    private ObjectProphecy $twig;

    /** @var ObjectProphecy<RouterInterface> */
    private ObjectProphecy $router;

    private Session $session;

    private RecordingLogger $logger;

    private RegisteredWebhooks $registeredWebhooks;

    /** @var list<PaymentMethod> */
    private array $paymentMethods;

    /** @var list<ShippingMethod> */
    private array $shippingMethods;

    protected function setUp(): void
    {
        $this->registeredWebhooksRepository = $this->prophesize(RegisteredWebhooksRepositoryInterface::class);
        $this->webhookRegistrar = $this->prophesize(WebhookRegistrarInterface::class);
        $this->paymentMethodRepository = $this->prophesize(PaymentMethodRepositoryInterface::class);
        $this->shippingMethodRepository = $this->prophesize(ShippingMethodRepositoryInterface::class);
        $this->httpClient = $this->prophesize(HttpClientInterface::class);
        $this->twig = $this->prophesize(Environment::class);
        $this->router = $this->prophesize(RouterInterface::class);
        $this->session = new Session(new MockArraySessionStorage());
        $this->logger = new RecordingLogger();

        $this->paymentMethods = [new PaymentMethod()];
        $this->shippingMethods = [new ShippingMethod()];
        $this->paymentMethodRepository->findAll()->willReturn($this->paymentMethods);
        $this->shippingMethodRepository->findAll()->willReturn($this->shippingMethods);

        // registered before the plugin recorded where, so there is nothing to compare with
        $this->registeredWebhooks = new RegisteredWebhooks();
        $this->registeredWebhooksRepository->findLatest()->willReturn($this->registeredWebhooks);
    }

    /**
     * @test
     */
    public function it_renders_the_sylius_and_shipmondo_payment_and_shipping_methods(): void
    {
        $this->givenShipmondoApi();

        $this->twig->render(self::TEMPLATE, Argument::that(function (array $context): bool {
            self::assertSame(['paymentMethods', 'shippingMethods', 'shipmondoPaymentMethods', 'shipmondoShipmentTemplates', 'paymentGatewaysFetched', 'shipmentTemplatesFetched', 'stalePaymentGateways', 'staleShipmentTemplates', 'registeredWebhooks', 'webhookComparison', 'registerWebhooksAutomatically'], array_keys($context));
            self::assertSame($this->paymentMethods, $context['paymentMethods']);
            self::assertSame($this->shippingMethods, $context['shippingMethods']);
            self::assertSame([11, 12], self::ids($context['shipmondoPaymentMethods']));
            self::assertSame([21], self::ids($context['shipmondoShipmentTemplates']));
            self::assertTrue($context['paymentGatewaysFetched']);
            self::assertTrue($context['shipmentTemplatesFetched']);
            self::assertSame([], self::staleMappings($context['stalePaymentGateways']));
            self::assertSame([], self::staleMappings($context['staleShipmentTemplates']));
            self::assertSame($this->registeredWebhooks, $context['registeredWebhooks']);
            // no endpoint is recorded to compare with
            self::assertNull($context['webhookComparison']);
            self::assertTrue($context['registerWebhooksAutomatically']);

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();
        $this->webhookRegistrar->compare(Argument::any())->shouldNotBeCalled();

        $response = $this->controller()->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('<h1>Shipmondo</h1>', $response->getContent());
        self::assertSame([], $this->session->getFlashBag()->peekAll());
    }

    /**
     * @test
     */
    public function it_tells_the_template_whether_the_upload_run_registers_the_webhooks_again(): void
    {
        $this->givenShipmondoApi();
        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context): bool {
            self::assertFalse($context['registerWebhooksAutomatically']);

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $this->controller(registerWebhooksAutomatically: false)->index(new Request());
    }

    /**
     * @test
     */
    public function it_tells_the_template_when_no_registration_is_recorded(): void
    {
        $this->givenShipmondoApi();
        $this->registeredWebhooksRepository->findLatest()->willReturn(null);
        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context): bool {
            self::assertNull($context['registeredWebhooks']);
            self::assertNull($context['webhookComparison']);

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();
        $this->webhookRegistrar->compare(Argument::any())->shouldNotBeCalled();

        $this->controller()->index(new Request());
    }

    /**
     * The page shows whether this shop's webhooks in Shipmondo match the ones the upload run keeps where an admin last
     * registered them
     *
     * @test
     */
    public function it_compares_this_shops_webhooks_in_shipmondo_at_the_endpoint_of_the_latest_registration(): void
    {
        $this->givenShipmondoApi();
        $this->registeredWebhooks->setEndpoint(self::ENDPOINT);
        $comparison = new WebhookComparison([], []);
        $this->webhookRegistrar->compare(self::ENDPOINT)->willReturn($comparison)->shouldBeCalledOnce();

        $this->twig->render(self::TEMPLATE, Argument::that(function (array $context) use ($comparison): bool {
            self::assertSame($this->registeredWebhooks, $context['registeredWebhooks']);
            self::assertSame($comparison, $context['webhookComparison']);

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $this->controller()->index(new Request());

        self::assertSame([], $this->session->getFlashBag()->peekAll());
        self::assertSame([], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_says_why_this_shops_webhooks_could_not_be_fetched(): void
    {
        $this->givenShipmondoApi();
        $this->registeredWebhooks->setEndpoint(self::ENDPOINT);
        $exception = new InternalServerErrorException(ShipmondoApi::json(['error' => 'Something went wrong'], 503));
        $this->webhookRegistrar->compare(self::ENDPOINT)->willThrow($exception);

        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context): bool {
            self::assertNull($context['webhookComparison']);
            // the other lists are still shown
            self::assertSame([11, 12], self::ids($context['shipmondoPaymentMethods']));
            self::assertSame([21], self::ids($context['shipmondoShipmentTemplates']));

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $this->controller()->index(new Request());

        self::assertSame([[
            'message' => 'setono_sylius_shipmondo.webhooks_not_fetched',
            'parameters' => ['%reason%' => 'Shipmondo could not be reached, or had a server error. Try again later.'],
        ]], $this->session->getFlashBag()->peek('error'));
        self::assertSame([[
            'level' => LogLevel::WARNING,
            'message' => 'Could not fetch this shop\'s webhooks from Shipmondo for the Shipmondo page',
            'context' => [
                'exception' => $exception,
                'shipmondoFailure' => 'temporary_failure',
                'shipmondoStatusCode' => 503,
                'shipmondoError' => 'Something went wrong',
            ],
        ]], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_explains_once_when_every_list_failed_for_the_same_reason(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::empty(401));
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->willReturn(ShipmondoApi::empty(401));
        $this->registeredWebhooks->setEndpoint(self::ENDPOINT);
        $this->webhookRegistrar->compare(self::ENDPOINT)->willThrow(new UnauthorizedException(ShipmondoApi::empty(401)));
        $this->givenRendered();

        $this->controller()->index(new Request());

        self::assertSame([[
            'message' => 'setono_sylius_shipmondo.shipmondo_data_and_webhooks_not_fetched',
            'parameters' => ['%reason%' => 'Shipmondo did not accept the API credentials. Check SHIPMONDO_USERNAME and SHIPMONDO_KEY, and that SHIPMONDO_SANDBOX matches the Shipmondo account they belong to.'],
        ]], $this->session->getFlashBag()->peek('error'));
    }

    /**
     * @test
     */
    public function it_explains_each_list_when_not_every_list_failed_for_the_same_reason(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::empty(401));
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->willReturn(ShipmondoApi::empty(401));
        $this->registeredWebhooks->setEndpoint(self::ENDPOINT);
        $this->webhookRegistrar->compare(self::ENDPOINT)->willThrow(new InternalServerErrorException(ShipmondoApi::empty(503)));
        $this->givenRendered();

        $this->controller()->index(new Request());

        $credentials = 'Shipmondo did not accept the API credentials. Check SHIPMONDO_USERNAME and SHIPMONDO_KEY, and that SHIPMONDO_SANDBOX matches the Shipmondo account they belong to.';
        self::assertSame([
            ['message' => 'setono_sylius_shipmondo.payment_gateways_not_fetched', 'parameters' => ['%reason%' => $credentials]],
            ['message' => 'setono_sylius_shipmondo.shipment_templates_not_fetched', 'parameters' => ['%reason%' => $credentials]],
            ['message' => 'setono_sylius_shipmondo.webhooks_not_fetched', 'parameters' => ['%reason%' => 'Shipmondo could not be reached, or had a server error. Try again later.']],
        ], $this->session->getFlashBag()->peek('error'));
    }

    /**
     * Two lists failed for the same reason, but the third was fetched, so the failure isn't the page's as a whole
     *
     * @test
     */
    public function it_explains_each_list_that_failed_when_another_was_fetched(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::empty(429));
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->willReturn(ShipmondoApi::empty(429));
        $this->registeredWebhooks->setEndpoint(self::ENDPOINT);
        $this->webhookRegistrar->compare(self::ENDPOINT)->willReturn(new WebhookComparison([], []));
        $this->givenRendered();

        $this->controller()->index(new Request());

        self::assertSame(
            ['setono_sylius_shipmondo.payment_gateways_not_fetched', 'setono_sylius_shipmondo.shipment_templates_not_fetched'],
            array_column($this->session->getFlashBag()->peek('error'), 'message'),
        );
    }

    /**
     * A payment gateway or shipment template deleted in Shipmondo stays mapped until an admin changes it, so the page
     * flags it
     *
     * @test
     */
    public function it_passes_the_mappings_that_no_longer_exist_in_shipmondo_to_the_template(): void
    {
        $this->givenShipmondoApi();

        $mapped = self::paymentMethod(11);
        $stalePaymentMethod = self::paymentMethod(99);
        $this->paymentMethodRepository->findAll()->willReturn([$mapped, $stalePaymentMethod, self::paymentMethod(null)]);

        $allowed = self::shippingMethod([21]);
        $staleShippingMethod = self::shippingMethod([98, 21, 97]);
        $this->shippingMethodRepository->findAll()->willReturn([$allowed, $staleShippingMethod, self::shippingMethod([])]);

        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context) use ($stalePaymentMethod, $staleShippingMethod): bool {
            self::assertSame([[$stalePaymentMethod, 99]], self::staleMappings($context['stalePaymentGateways']));
            self::assertSame([[$staleShippingMethod, [98, 97]]], self::staleMappings($context['staleShipmentTemplates']));

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $this->controller()->index(new Request());
    }

    /**
     * The payment methods are still listed when only the shipment templates couldn't be fetched, so a stale mapping
     * must still be flagged and shown, or saving the form would remove it
     *
     * @test
     */
    public function it_flags_the_stale_payment_gateways_when_only_the_shipment_templates_could_not_be_fetched(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::json([
            ['id' => 11, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '1234'],
        ]));
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->willReturn(ShipmondoApi::empty(401))->shouldBeCalledOnce();

        $stalePaymentMethod = self::paymentMethod(99);
        $this->paymentMethodRepository->findAll()->willReturn([$stalePaymentMethod]);
        $this->shippingMethodRepository->findAll()->willReturn([self::shippingMethod([98])]);

        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context) use ($stalePaymentMethod): bool {
            self::assertTrue($context['paymentGatewaysFetched']);
            self::assertSame([11], self::ids($context['shipmondoPaymentMethods']));
            self::assertSame([[$stalePaymentMethod, 99]], self::staleMappings($context['stalePaymentGateways']));
            self::assertFalse($context['shipmentTemplatesFetched']);
            self::assertSame([], $context['shipmondoShipmentTemplates']);
            self::assertSame([], self::staleMappings($context['staleShipmentTemplates']));

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $this->controller()->index(new Request());

        self::assertSame([[
            'message' => 'setono_sylius_shipmondo.shipment_templates_not_fetched',
            'parameters' => ['%reason%' => 'Shipmondo did not accept the API credentials. Check SHIPMONDO_USERNAME and SHIPMONDO_KEY, and that SHIPMONDO_SANDBOX matches the Shipmondo account they belong to.'],
        ]], $this->session->getFlashBag()->peek('error'));
        self::assertCount(1, $this->logger->records);
        self::assertSame('Could not fetch the shipment templates from Shipmondo for the Shipmondo page', $this->logger->records[0]['message']);
    }

    /**
     * @test
     */
    public function it_flags_the_mappings_as_saved(): void
    {
        $this->givenShipmondoApi();

        $paymentMethod = self::paymentMethod(99);
        $this->paymentMethodRepository->findAll()->willReturn([$paymentMethod]);
        $this->paymentMethodRepository->find(1)->willReturn($paymentMethod);
        $this->paymentMethodRepository->add($paymentMethod)->shouldBeCalledOnce();

        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context): bool {
            self::assertSame([], self::staleMappings($context['stalePaymentGateways']));

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $this->controller()->index(self::post(['payment_methods' => [1 => '11']]));
    }

    /**
     * When neither list could be fetched for the same reason, e.g. because Shipmondo rejects the credentials, one flash
     * message names both
     *
     * @test
     */
    public function it_still_renders_the_page_when_the_shipmondo_api_fails(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::empty(401));
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->willReturn(ShipmondoApi::empty(401))->shouldBeCalledOnce();

        // without Shipmondo's payment gateways and shipment templates, no mapping is known to be stale
        $this->paymentMethodRepository->findAll()->willReturn([self::paymentMethod(99)]);
        $this->shippingMethodRepository->findAll()->willReturn([self::shippingMethod([98])]);

        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context): bool {
            self::assertSame([], $context['shipmondoPaymentMethods']);
            self::assertSame([], $context['shipmondoShipmentTemplates']);
            self::assertFalse($context['paymentGatewaysFetched']);
            self::assertFalse($context['shipmentTemplatesFetched']);
            self::assertSame([], self::staleMappings($context['stalePaymentGateways']));
            self::assertSame([], self::staleMappings($context['staleShipmentTemplates']));

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $response = $this->controller()->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        self::assertSame([[
            'message' => 'setono_sylius_shipmondo.shipmondo_data_not_fetched',
            'parameters' => ['%reason%' => 'Shipmondo did not accept the API credentials. Check SHIPMONDO_USERNAME and SHIPMONDO_KEY, and that SHIPMONDO_SANDBOX matches the Shipmondo account they belong to.'],
        ]], $this->session->getFlashBag()->peek('error'));
        self::assertSame([
            [LogLevel::WARNING, 'Could not fetch the payment gateways from Shipmondo for the Shipmondo page', 'credentials_failure'],
            [LogLevel::WARNING, 'Could not fetch the shipment templates from Shipmondo for the Shipmondo page', 'credentials_failure'],
        ], array_map(static fn (array $record): array => [$record['level'], $record['message'], $record['context']['shipmondoFailure']], $this->logger->records));
    }

    /**
     * Each list is fetched on its own, so the shipping methods can still be mapped when the payment gateways couldn't
     * be fetched
     *
     * @test
     */
    public function it_fetches_the_shipment_templates_when_the_payment_gateways_could_not_be_fetched(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::empty(503));
        $this->givenShipmentTemplates();

        $this->paymentMethodRepository->findAll()->willReturn([self::paymentMethod(99)]);
        $staleShippingMethod = self::shippingMethod([98, 21]);
        $this->shippingMethodRepository->findAll()->willReturn([$staleShippingMethod]);

        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context) use ($staleShippingMethod): bool {
            self::assertFalse($context['paymentGatewaysFetched']);
            self::assertSame([], $context['shipmondoPaymentMethods']);
            // without Shipmondo's payment gateways, no payment gateway mapping is known to be stale
            self::assertSame([], self::staleMappings($context['stalePaymentGateways']));

            self::assertTrue($context['shipmentTemplatesFetched']);
            self::assertSame([21], self::ids($context['shipmondoShipmentTemplates']));
            self::assertSame([[$staleShippingMethod, [98]]], self::staleMappings($context['staleShipmentTemplates']));

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $response = $this->controller()->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame([[
            'message' => 'setono_sylius_shipmondo.payment_gateways_not_fetched',
            'parameters' => ['%reason%' => 'Shipmondo could not be reached, or had a server error. Try again later.'],
        ]], $this->session->getFlashBag()->peek('error'));
        self::assertCount(1, $this->logger->records);
        self::assertSame(LogLevel::WARNING, $this->logger->records[0]['level']);
        self::assertSame('Could not fetch the payment gateways from Shipmondo for the Shipmondo page', $this->logger->records[0]['message']);
        self::assertSame('temporary_failure', $this->logger->records[0]['context']['shipmondoFailure']);
        self::assertSame(503, $this->logger->records[0]['context']['shipmondoStatusCode']);
    }

    /**
     * @test
     */
    public function it_explains_each_list_that_could_not_be_fetched_for_its_own_reason(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::empty(429));
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->willReturn(ShipmondoApi::empty(503));
        $this->givenRendered();

        $this->controller()->index(new Request());

        self::assertSame([
            [
                'message' => 'setono_sylius_shipmondo.payment_gateways_not_fetched',
                'parameters' => ['%reason%' => 'Shipmondo is limiting how often the store may call it. Try again in a minute.'],
            ],
            [
                'message' => 'setono_sylius_shipmondo.shipment_templates_not_fetched',
                'parameters' => ['%reason%' => 'Shipmondo could not be reached, or had a server error. Try again later.'],
            ],
        ], $this->session->getFlashBag()->peek('error'));
    }

    /**
     * @test
     */
    public function it_leaves_a_failure_that_is_not_shipmondos_to_fail_loudly(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willThrow(new \LogicException('A bug'));
        $this->twig->render(Argument::cetera())->shouldNotBeCalled();

        $this->expectExceptionObject(new \LogicException('A bug'));

        $this->controller()->index(new Request());
    }

    /**
     * @test
     */
    public function it_maps_the_posted_shipmondo_payment_gateways_to_the_payment_methods(): void
    {
        $this->givenShipmondoApi();
        $this->givenRendered();

        $unmapped = new PaymentMethod();
        $unmapped->setShipmondoId(12);
        $paymentMethod = new PaymentMethod();
        $this->paymentMethodRepository->find(1)->willReturn($unmapped);
        $this->paymentMethodRepository->find(2)->willReturn(null);
        $this->paymentMethodRepository->find(3)->willReturn($paymentMethod);
        $this->paymentMethodRepository->add($unmapped)->shouldBeCalledOnce();
        $this->paymentMethodRepository->add($paymentMethod)->shouldBeCalledOnce();

        // the empty choice removes a mapping, and an unknown payment method must not stop the mapping of the following ones
        $this->controller()->index(self::post(['payment_methods' => [1 => '', 2 => '12', 3 => '11']]));

        self::assertNull($unmapped->getShipmondoId());
        self::assertSame(11, $paymentMethod->getShipmondoId());
    }

    /**
     * @test
     */
    public function it_rejects_a_posted_payment_method_that_is_not_a_shipmondo_payment_method(): void
    {
        $this->givenShipmondoApi();
        $this->paymentMethodRepository->find(1)->willReturn(new BasePaymentMethod());
        $this->paymentMethodRepository->add(Argument::any())->shouldNotBeCalled();

        $this->expectException(\InvalidArgumentException::class);

        $this->controller()->index(self::post(['payment_methods' => [1 => '11']]));
    }

    /**
     * @test
     */
    public function it_maps_the_posted_shipmondo_shipment_templates_to_the_shipping_methods(): void
    {
        $this->givenShipmondoApi();
        $this->givenRendered();

        $shippingMethod = new ShippingMethod();
        $this->shippingMethodRepository->find(99)->willReturn(null);
        $this->shippingMethodRepository->find(1)->willReturn($shippingMethod);
        $this->shippingMethodRepository->add($shippingMethod)->shouldBeCalledOnce();

        // an unknown shipping method must not stop the mapping of the following ones
        $this->controller()->index(self::post([
            'listed_shipping_methods' => ['99', '1'],
            'shipping_methods' => [99 => ['21'], 1 => ['664116', '664115']],
        ]));

        self::assertSame([664116, 664115], $shippingMethod->getAllowedShipmentTemplates());
    }

    /**
     * A select without a selected shipment template isn't submitted, so only the list of the form's shipping methods
     * names them
     *
     * @test
     */
    public function it_removes_the_shipment_templates_of_a_listed_shipping_method_without_a_selection(): void
    {
        $this->givenShipmondoApi();
        $this->givenRendered();

        $cleared = new ShippingMethod();
        $cleared->setAllowedShipmentTemplates([21]);
        $mapped = new ShippingMethod();
        $this->shippingMethodRepository->find('1')->willReturn($cleared);
        $this->shippingMethodRepository->find('2')->willReturn($mapped);
        $this->shippingMethodRepository->add($cleared)->shouldBeCalledOnce();
        $this->shippingMethodRepository->add($mapped)->shouldBeCalledOnce();

        $this->controller()->index(self::post(['listed_shipping_methods' => ['1', '2'], 'shipping_methods' => [2 => ['664116']]]));

        self::assertSame([], $cleared->getAllowedShipmentTemplates());
        self::assertSame([664116], $mapped->getAllowedShipmentTemplates());
    }

    /**
     * @test
     */
    public function it_removes_the_shipment_templates_of_every_shipping_method_when_none_has_a_selection(): void
    {
        $this->givenShipmondoApi();
        $this->givenRendered();

        $shippingMethod = new ShippingMethod();
        $shippingMethod->setAllowedShipmentTemplates([21]);
        $this->shippingMethodRepository->find('1')->willReturn($shippingMethod);
        $this->shippingMethodRepository->add($shippingMethod)->shouldBeCalledOnce();

        // no shipping_methods at all
        $this->controller()->index(self::post(['listed_shipping_methods' => ['1']]));

        self::assertSame([], $shippingMethod->getAllowedShipmentTemplates());
    }

    /**
     * A template override that doesn't list its shipping methods can still map shipment templates
     *
     * @test
     */
    public function it_maps_the_posted_shipment_templates_without_the_list_of_shipping_methods(): void
    {
        $this->givenShipmondoApi();
        $this->givenRendered();

        $shippingMethod = new ShippingMethod();
        $this->shippingMethodRepository->find(1)->willReturn($shippingMethod);
        $this->shippingMethodRepository->add($shippingMethod)->shouldBeCalledOnce();

        $this->controller()->index(self::post(['shipping_methods' => [1 => ['664116']]]));

        self::assertSame([664116], $shippingMethod->getAllowedShipmentTemplates());
    }

    /**
     * @test
     */
    public function it_rejects_a_posted_shipping_method_that_is_not_a_shipmondo_shipping_method(): void
    {
        $this->givenShipmondoApi();
        $this->shippingMethodRepository->find(1)->willReturn(new BaseShippingMethod());
        $this->shippingMethodRepository->add(Argument::any())->shouldNotBeCalled();

        $this->expectException(\InvalidArgumentException::class);

        $this->controller()->index(self::post(['shipping_methods' => [1 => ['21']]]));
    }

    /**
     * @test
     */
    public function it_only_saves_the_mappings_on_post(): void
    {
        $this->givenShipmondoApi();
        $this->givenRendered();

        $this->paymentMethodRepository->find(Argument::any())->shouldNotBeCalled();
        $this->shippingMethodRepository->find(Argument::any())->shouldNotBeCalled();

        $request = new Request([], ['payment_methods' => [1 => '11'], 'shipping_methods' => [1 => ['21']]]);
        self::assertSame('GET', $request->getMethod());

        $this->controller()->index($request);
    }

    /**
     * @test
     */
    public function it_registers_the_webhooks_and_redirects_back_to_the_shipmondo_page(): void
    {
        $this->webhookRegistrar->register()->shouldBeCalledOnce();
        $this->router->generate('setono_sylius_shipmondo_admin_shipmondo_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/admin/shipmondo')
        ;

        $response = $this->controller()->registerWebhooks();

        self::assertSame('/admin/shipmondo', $response->getTargetUrl());
        self::assertSame(['setono_sylius_shipmondo.webhooks_registered'], $this->session->getFlashBag()->peek('success'));
        self::assertSame([], $this->session->getFlashBag()->peek('error'));
    }

    /**
     * @test
     */
    public function it_explains_why_the_webhooks_could_not_be_registered(): void
    {
        $exception = new UnauthorizedException(ShipmondoApi::json(['error' => 'Invalid credentials'], 401));
        $this->webhookRegistrar->register()->willThrow($exception);
        $this->router->generate('setono_sylius_shipmondo_admin_shipmondo_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/admin/shipmondo')
        ;

        $response = $this->controller()->registerWebhooks();

        self::assertSame('/admin/shipmondo', $response->getTargetUrl());
        self::assertSame([], $this->session->getFlashBag()->peek('success'));
        self::assertSame([[
            'message' => 'setono_sylius_shipmondo.webhooks_not_registered',
            'parameters' => ['%reason%' => 'Shipmondo did not accept the API credentials. Check SHIPMONDO_USERNAME and SHIPMONDO_KEY, and that SHIPMONDO_SANDBOX matches the Shipmondo account they belong to.'],
        ]], $this->session->getFlashBag()->peek('error'));
        self::assertSame([[
            'level' => LogLevel::ERROR,
            'message' => 'The webhooks could not be registered in Shipmondo',
            'context' => [
                'exception' => $exception,
                'shipmondoFailure' => 'credentials_failure',
                'shipmondoStatusCode' => 401,
                'shipmondoError' => 'Invalid credentials',
            ],
        ]], $this->logger->records);
    }

    /**
     * In a request the webhooks' endpoint is at the host the admin is opened at, e.g. localhost on a developer's
     * machine. The registrar refuses it before it asks Shipmondo anything
     *
     * @test
     */
    public function it_explains_that_shipmondo_cannot_reach_the_webhooks_endpoint(): void
    {
        $this->webhookRegistrar->register()->willThrow(new UnreachableWebhookEndpointException('https://127.0.0.1:8000/webhook/shipmondo'));
        $this->router->generate('setono_sylius_shipmondo_admin_shipmondo_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/admin/shipmondo')
        ;

        $response = $this->controller()->registerWebhooks();

        self::assertSame('/admin/shipmondo', $response->getTargetUrl());
        self::assertSame([], $this->session->getFlashBag()->peek('success'));
        self::assertSame([[
            'message' => 'setono_sylius_shipmondo.webhooks_not_registered_at_unreachable_endpoint',
            'parameters' => ['%endpoint%' => 'https://127.0.0.1:8000/webhook/shipmondo'],
        ]], $this->session->getFlashBag()->peek('error'));
        self::assertSame([], $this->logger->records);
    }

    /**
     * @test
     */
    public function it_leaves_a_registration_failure_that_is_not_shipmondos_to_fail_loudly(): void
    {
        $failure = new \LogicException('A bug');
        $this->webhookRegistrar->register()->willThrow($failure);

        try {
            $this->controller()->registerWebhooks();
            self::fail('The failure was not let through');
        } catch (\LogicException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([], $this->session->getFlashBag()->peekAll());
    }

    private function givenShipmondoApi(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::json([
            ['id' => 11, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '1234'],
            ['id' => 12, 'name' => 'Stripe', 'provider' => 'stripe', 'merchant_number' => '5678'],
        ]));
        $this->givenShipmentTemplates();
    }

    private function givenShipmentTemplates(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->willReturn(ShipmondoApi::json([
            [
                'id' => 21,
                'name' => 'GLS Private',
                'sender' => ['country_code' => 'DK'],
                'receiver' => ['country_code' => 'DK'],
                'parcels' => [['quantity' => 1, 'weight' => 1000]],
            ],
        ]));
    }

    private function givenRendered(): void
    {
        $this->twig->render(self::TEMPLATE, Argument::type('array'))->willReturn('<h1>Shipmondo</h1>');
    }

    /**
     * Returns the ids of the Shipmondo payment gateways / shipment templates passed to the template
     *
     * @return list<int>
     */
    private static function ids(mixed $resources): array
    {
        self::assertIsArray($resources);

        $ids = [];
        foreach ($resources as $resource) {
            self::assertTrue($resource instanceof PaymentGateway || $resource instanceof ShipmentTemplate);
            $ids[] = $resource->id;
        }

        return $ids;
    }

    /**
     * Returns each Sylius method the template is told has a stale mapping, with what is stale
     *
     * @return list<array{object, mixed}>
     */
    private static function staleMappings(mixed $staleMappings): array
    {
        self::assertInstanceOf(\SplObjectStorage::class, $staleMappings);

        $pairs = [];
        foreach ($staleMappings as $method) {
            $pairs[] = [$method, $staleMappings[$method]];
        }

        return $pairs;
    }

    private static function paymentMethod(?int $paymentGateway): PaymentMethod
    {
        $paymentMethod = new PaymentMethod();
        $paymentMethod->setShipmondoId($paymentGateway);

        return $paymentMethod;
    }

    /**
     * @param list<int> $allowedShipmentTemplates
     */
    private static function shippingMethod(array $allowedShipmentTemplates): ShippingMethod
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setAllowedShipmentTemplates($allowedShipmentTemplates);

        return $shippingMethod;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function post(array $parameters): Request
    {
        return Request::create('/admin/shipmondo', 'POST', $parameters);
    }

    private function controller(bool $registerWebhooksAutomatically = true): ShipmondoController
    {
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        $container = new Container();
        $container->set('twig', $this->twig->reveal());
        $container->set('router', $this->router->reveal());
        $container->set('request_stack', $requestStack);

        $controller = new ShipmondoController(
            $this->registeredWebhooksRepository->reveal(),
            $this->webhookRegistrar->reveal(),
            $this->paymentMethodRepository->reveal(),
            $this->shippingMethodRepository->reveal(),
            new SetupProvider(ShipmondoApi::client($this->httpClient->reveal())),
            Translations::translator(),
            $this->logger,
            $registerWebhooksAutomatically,
        );
        $controller->setContainer($container);

        return $controller;
    }
}
