<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Controller\Shop;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Psr\Http\Message\RequestInterface;
use Setono\Shipmondo\Response\PickupPoint\PickupPoint;
use Setono\SyliusShipmondoPlugin\Controller\Shop\GetPickupPointsAction;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Order as BaseOrder;
use Sylius\Component\Core\Model\Shipment as BaseShipment;
use Sylius\Component\Core\Model\ShippingMethod as BaseShippingMethod;
use Sylius\Component\Core\Repository\ShippingMethodRepositoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Context\CartNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Order;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Shipment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Twig\Environment;

final class GetPickupPointsActionTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ShippingMethodRepositoryInterface> */
    private ObjectProphecy $shippingMethodRepository;

    /** @var ObjectProphecy<CartContextInterface> */
    private ObjectProphecy $cartContext;

    /** @var ObjectProphecy<HttpClientInterface> */
    private ObjectProphecy $httpClient;

    /** @var ObjectProphecy<Environment> */
    private ObjectProphecy $twig;

    protected function setUp(): void
    {
        $this->shippingMethodRepository = $this->prophesize(ShippingMethodRepositoryInterface::class);
        $this->cartContext = $this->prophesize(CartContextInterface::class);
        $this->httpClient = $this->prophesize(HttpClientInterface::class);
        $this->twig = $this->prophesize(Environment::class);
    }

    /**
     * @test
     */
    public function it_returns_the_pickup_points_near_the_shipping_address(): void
    {
        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn(self::cart());
        $this->givenPickupPoints(['1', '2', '3']);
        $this->expectRenderedPickupPoints(['1', '2', '3']);

        $response = $this->invoke();

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(['1', '2', '3'], self::pickupPointIds($response));
        self::assertSame('<div>pickup points</div>', self::json($response)['html']);
    }

    /**
     * @test
     */
    public function it_puts_the_already_chosen_pickup_point_first(): void
    {
        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn(self::cart(['id' => '3']));
        $this->givenPickupPoints(['1', '2', '3', '4']);
        $this->expectRenderedPickupPoints(['3', '1', '2', '4']);

        self::assertSame(['3', '1', '2', '4'], self::pickupPointIds($this->invoke()));
    }

    /**
     * @test
     */
    public function it_keeps_the_order_when_the_chosen_pickup_point_has_no_id(): void
    {
        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn(self::cart(['name' => 'Pakkeshop']));
        $this->givenPickupPoints(['1', '2']);
        $this->expectRenderedPickupPoints(['1', '2']);

        self::assertSame(['1', '2'], self::pickupPointIds($this->invoke()));
    }

    /**
     * @test
     */
    public function it_keeps_the_order_when_the_cart_has_no_shipment(): void
    {
        $order = self::cart();
        foreach ($order->getShipments() as $shipment) {
            $order->removeShipment($shipment);
        }

        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn($order);
        $this->givenPickupPoints(['1', '2']);
        $this->expectRenderedPickupPoints(['1', '2']);

        self::assertSame(['1', '2'], self::pickupPointIds($this->invoke()));
    }

    /**
     * @test
     */
    public function it_keeps_the_order_when_the_shipment_is_not_a_shipmondo_shipment(): void
    {
        $order = self::cart();
        foreach ($order->getShipments() as $shipment) {
            $order->removeShipment($shipment);
        }
        $order->addShipment(new BaseShipment());

        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn($order);
        $this->givenPickupPoints(['1', '2']);
        $this->expectRenderedPickupPoints(['1', '2']);

        self::assertSame(['1', '2'], self::pickupPointIds($this->invoke()));
    }

    /**
     * @test
     */
    public function it_rejects_a_chosen_pickup_point_with_an_id_that_is_not_a_string(): void
    {
        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn(self::cart(['id' => 3]));
        $this->givenPickupPoints(['1', '3']);

        $this->expectException(\InvalidArgumentException::class);

        $this->invoke();
    }

    /**
     * @test
     */
    public function it_requires_a_shipping_method(): void
    {
        $this->expectNoSearch();

        self::assertBadRequest($this->invoke(new Request()));
    }

    /**
     * @test
     */
    public function it_requires_an_enabled_shipping_method(): void
    {
        $this->shippingMethodRepository->findOneBy(['code' => 'gls_pickup', 'enabled' => true])->willReturn(null);
        $this->expectNoSearch();

        self::assertBadRequest($this->invoke());
    }

    /**
     * @test
     */
    public function it_requires_a_shipmondo_shipping_method(): void
    {
        $this->shippingMethodRepository->findOneBy(['code' => 'gls_pickup', 'enabled' => true])->willReturn(new BaseShippingMethod());
        $this->expectNoSearch();

        self::assertBadRequest($this->invoke());
    }

    /**
     * @test
     */
    public function it_requires_a_cart(): void
    {
        $this->givenShippingMethod();
        $this->cartContext->getCart()->willThrow(new CartNotFoundException());
        $this->expectNoSearch();

        self::assertBadRequest($this->invoke());
    }

    /**
     * @test
     */
    public function it_requires_a_shipmondo_order(): void
    {
        // a complete cart, so only the order type keeps the search from happening
        $order = new BaseOrder();
        $order->setShippingAddress(self::cart()->getShippingAddress());

        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn($order);
        $this->expectNoSearch();

        self::assertBadRequest($this->invoke());
    }

    /**
     * @test
     */
    public function it_requires_a_shipping_address(): void
    {
        $this->givenShippingMethod();
        $this->cartContext->getCart()->willReturn(new Order());
        $this->expectNoSearch();

        self::assertBadRequest($this->invoke());
    }

    /**
     * @test
     *
     * @dataProvider provideIncompleteSearches
     */
    public function it_rejects_a_search_with_missing_data(?string $carrierCode, ?string $countryCode, ?string $postcode, ?string $street): void
    {
        $this->givenShippingMethod($carrierCode);

        $address = new Address();
        $address->setCountryCode($countryCode);
        $address->setPostcode($postcode);
        $address->setStreet($street);
        $order = new Order();
        $order->setShippingAddress($address);
        $this->cartContext->getCart()->willReturn($order);

        $this->expectNoSearch();

        self::assertBadRequest($this->invoke());
    }

    /**
     * @return \Generator<string, array{string|null, string|null, string|null, string|null}>
     */
    public function provideIncompleteSearches(): \Generator
    {
        yield 'no carrier code' => [null, 'DK', '9000', 'Algade 1'];
        yield 'no country' => ['gls', null, '9000', 'Algade 1'];
        yield 'no postcode' => ['gls', 'DK', null, 'Algade 1'];
        yield 'no street' => ['gls', 'DK', '9000', null];
    }

    private function givenShippingMethod(?string $carrierCode = 'gls'): void
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setCode('gls_pickup');
        $shippingMethod->setPickupPointDelivery(true);
        $shippingMethod->setCarrierCode($carrierCode);

        $this->shippingMethodRepository->findOneBy(['code' => 'gls_pickup', 'enabled' => true])->willReturn($shippingMethod);
    }

    /**
     * @param list<string> $ids
     */
    private function givenPickupPoints(array $ids): void
    {
        $this->httpClient->sendRequest(Argument::that(static function (RequestInterface $request): bool {
            parse_str($request->getUri()->getQuery(), $query);

            return 'GET' === $request->getMethod() &&
                str_ends_with($request->getUri()->getPath(), '/pickup_points') &&
                ['carrier_code' => 'gls', 'country_code' => 'DK', 'zipcode' => '9000', 'address' => 'Algade 1'] === $query;
        }))->willReturn(ShipmondoApi::json(array_map(static fn (string $id): array => [
            'id' => $id,
            'number' => $id,
            'company_name' => 'Pakkeshop ' . $id,
            'name' => 'Pakkeshop ' . $id,
            'address' => 'Algade ' . $id,
            'zipcode' => '9000',
            'city' => 'Aalborg',
            'country' => 'DK',
            'opening_hours' => ['Man-Fre: 08:00-20:00'],
        ], $ids)))->shouldBeCalledOnce();
    }

    /**
     * @param list<string> $ids
     */
    private function expectRenderedPickupPoints(array $ids): void
    {
        $this->twig->render('@SetonoSyliusShipmondoPlugin/shop/ajax/pickup_points.html.twig', Argument::that(
            static function (array $context) use ($ids): bool {
                if (['pickupPoints'] !== array_keys($context) || !is_array($context['pickupPoints'])) {
                    return false;
                }

                $renderedIds = [];
                foreach ($context['pickupPoints'] as $pickupPoint) {
                    if (!$pickupPoint instanceof PickupPoint) {
                        return false;
                    }

                    $renderedIds[] = $pickupPoint->id;
                }

                return $ids === $renderedIds;
            },
        ))->willReturn('<div>pickup points</div>')->shouldBeCalledOnce();
    }

    private function expectNoSearch(): void
    {
        $this->httpClient->sendRequest(Argument::any())->shouldNotBeCalled();
        $this->twig->render(Argument::cetera())->shouldNotBeCalled();
    }

    /**
     * @param array<string, mixed>|null $chosenPickupPoint
     */
    private static function cart(?array $chosenPickupPoint = null): Order
    {
        $address = new Address();
        $address->setCountryCode('DK');
        $address->setPostcode('9000');
        $address->setStreet('Algade 1');

        $shipment = new Shipment();
        $shipment->setShipmondoPickupPoint($chosenPickupPoint);

        $order = new Order();
        $order->setShippingAddress($address);
        $order->addShipment($shipment);

        return $order;
    }

    private function invoke(?Request $request = null): Response
    {
        $action = new GetPickupPointsAction(
            $this->shippingMethodRepository->reveal(),
            $this->cartContext->reveal(),
            ShipmondoApi::client($this->httpClient->reveal()),
            $this->twig->reveal(),
        );

        return $action($request ?? new Request(['shippingMethod' => 'gls_pickup']));
    }

    private static function assertBadRequest(Response $response): void
    {
        self::assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(Response $response): array
    {
        /** @var array<string, mixed> $data */
        $data = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * @return list<mixed>
     */
    private static function pickupPointIds(Response $response): array
    {
        $pickupPoints = self::json($response)['pickupPoints'];
        self::assertIsArray($pickupPoints);

        return array_map(static function (mixed $pickupPoint): mixed {
            self::assertIsArray($pickupPoint);

            return $pickupPoint['id'];
        }, array_values($pickupPoints));
    }
}
