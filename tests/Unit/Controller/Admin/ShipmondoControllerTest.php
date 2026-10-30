<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Controller\Admin;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Setono\Shipmondo\Response\PaymentGateway\PaymentGateway;
use Setono\Shipmondo\Response\ShipmentTemplate\ShipmentTemplate;
use Setono\SyliusShipmondoPlugin\Controller\Admin\ShipmondoController;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooks;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\PaymentMethod;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Twig\Environment;

final class ShipmondoControllerTest extends TestCase
{
    use ProphecyTrait;

    private const TEMPLATE = '@SetonoSyliusShipmondoPlugin/admin/shipmondo/index.html.twig';

    /** @var ObjectProphecy<RegisteredWebhooksRepositoryInterface> */
    private ObjectProphecy $registeredWebhooksRepository;

    /** @var ObjectProphecy<MessageBusInterface> */
    private ObjectProphecy $commandBus;

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

    private RegisteredWebhooks $registeredWebhooks;

    /** @var list<PaymentMethod> */
    private array $paymentMethods;

    /** @var list<ShippingMethod> */
    private array $shippingMethods;

    protected function setUp(): void
    {
        $this->registeredWebhooksRepository = $this->prophesize(RegisteredWebhooksRepositoryInterface::class);
        $this->commandBus = $this->prophesize(MessageBusInterface::class);
        $this->webhookRegistrar = $this->prophesize(WebhookRegistrarInterface::class);
        $this->paymentMethodRepository = $this->prophesize(PaymentMethodRepositoryInterface::class);
        $this->shippingMethodRepository = $this->prophesize(ShippingMethodRepositoryInterface::class);
        $this->httpClient = $this->prophesize(HttpClientInterface::class);
        $this->twig = $this->prophesize(Environment::class);
        $this->router = $this->prophesize(RouterInterface::class);
        $this->session = new Session(new MockArraySessionStorage());

        $this->paymentMethods = [new PaymentMethod()];
        $this->shippingMethods = [new ShippingMethod()];
        $this->paymentMethodRepository->findAll()->willReturn($this->paymentMethods);
        $this->shippingMethodRepository->findAll()->willReturn($this->shippingMethods);

        $this->registeredWebhooks = new RegisteredWebhooks();
        $this->webhookRegistrar->getVersion()->willReturn('v1');
        $this->registeredWebhooksRepository->findOneByVersion('v1')->willReturn($this->registeredWebhooks);
    }

    /**
     * @test
     */
    public function it_renders_the_sylius_and_shipmondo_payment_and_shipping_methods(): void
    {
        $this->givenShipmondoApi();

        $this->twig->render(self::TEMPLATE, Argument::that(function (array $context): bool {
            self::assertSame(['paymentMethods', 'shippingMethods', 'shipmondoPaymentMethods', 'shipmondoShipmentTemplates', 'registeredWebhooks'], array_keys($context));
            self::assertSame($this->paymentMethods, $context['paymentMethods']);
            self::assertSame($this->shippingMethods, $context['shippingMethods']);
            self::assertSame([11, 12], self::ids($context['shipmondoPaymentMethods']));
            self::assertSame([21], self::ids($context['shipmondoShipmentTemplates']));
            self::assertSame($this->registeredWebhooks, $context['registeredWebhooks']);

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $response = $this->controller()->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('<h1>Shipmondo</h1>', $response->getContent());
        self::assertSame([], $this->session->getFlashBag()->peekAll());
    }

    /**
     * @test
     */
    public function it_still_renders_the_page_when_the_shipmondo_api_fails(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::empty(401));
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))->shouldNotBeCalled();

        $this->twig->render(self::TEMPLATE, Argument::that(static function (array $context): bool {
            self::assertSame([], $context['shipmondoPaymentMethods']);
            self::assertSame([], $context['shipmondoShipmentTemplates']);

            return true;
        }))->willReturn('<h1>Shipmondo</h1>')->shouldBeCalledOnce();

        $response = $this->controller()->index(new Request());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $errors = $this->session->getFlashBag()->peek('error');
        self::assertCount(1, $errors);
        self::assertIsString($errors[0]);
        self::assertStringStartsWith('Could not fetch data from the Shipmondo API. Please check your Shipmondo credentials. Error: ', $errors[0]);
        self::assertStringContainsString('401', $errors[0]);
    }

    /**
     * @test
     */
    public function it_maps_the_posted_shipmondo_payment_gateways_to_the_payment_methods(): void
    {
        $this->givenShipmondoApi();
        $this->givenRendered();

        $paymentMethod = new PaymentMethod();
        $this->paymentMethodRepository->find(1)->shouldNotBeCalled();
        $this->paymentMethodRepository->find(2)->willReturn(null);
        $this->paymentMethodRepository->find(3)->willReturn($paymentMethod);
        $this->paymentMethodRepository->add($paymentMethod)->shouldBeCalledOnce();

        // an unmapped payment method and an unknown payment method must not stop the mapping of the following ones
        $this->controller()->index(self::post(['payment_methods' => [1 => '', 2 => '12', 3 => '11']]));

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
        $this->controller()->index(self::post(['shipping_methods' => [99 => ['21'], 1 => ['664116', '664115']]]));

        self::assertSame([664116, 664115], $shippingMethod->getAllowedShipmentTemplates());
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
        $this->commandBus->dispatch(Argument::type(RegisterWebhooks::class))
            ->willReturn(new Envelope(new RegisterWebhooks()))
            ->shouldBeCalledOnce()
        ;
        $this->router->generate('setono_sylius_shipmondo_admin_shipmondo_index', [], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/admin/shipmondo')
        ;

        $response = $this->controller()->registerWebhooks();

        self::assertSame('/admin/shipmondo', $response->getTargetUrl());
        self::assertSame(['Webhooks registered successfully'], $this->session->getFlashBag()->peek('success'));
    }

    private function givenShipmondoApi(): void
    {
        $this->httpClient->sendRequest(ShipmondoApi::request('GET', 'payment_gateways'))->willReturn(ShipmondoApi::json([
            ['id' => 11, 'name' => 'Quickpay', 'provider' => 'quickpay', 'merchant_number' => '1234'],
            ['id' => 12, 'name' => 'Stripe', 'provider' => 'stripe', 'merchant_number' => '5678'],
        ]));
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
     * @param array<string, mixed> $parameters
     */
    private static function post(array $parameters): Request
    {
        return Request::create('/admin/shipmondo', 'POST', $parameters);
    }

    private function controller(): ShipmondoController
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
            $this->commandBus->reveal(),
            $this->webhookRegistrar->reveal(),
            $this->paymentMethodRepository->reveal(),
            $this->shippingMethodRepository->reveal(),
            ShipmondoApi::client($this->httpClient->reveal()),
        );
        $controller->setContainer($container);

        return $controller;
    }
}
