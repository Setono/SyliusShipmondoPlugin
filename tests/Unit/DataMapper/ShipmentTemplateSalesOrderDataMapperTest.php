<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Client\ClientInterface as HttpClientInterface;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\ShipmentTemplateSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariant;
use Sylius\Component\Core\Model\Shipment as BaseShipment;
use Sylius\Component\Core\Model\ShippingMethod as BaseShippingMethod;
use Sylius\Component\Core\Model\ShopBillingData;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\Shipment;
use Tests\Setono\SyliusShipmondoPlugin\Application\Model\ShippingMethod;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;

final class ShipmentTemplateSalesOrderDataMapperTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<HttpClientInterface> */
    private ObjectProphecy $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->prophesize(HttpClientInterface::class);
    }

    /**
     * @test
     */
    public function it_maps_the_allowed_shipment_template_that_fits_the_order_weight_best(): void
    {
        $this->givenShipmentTemplates([
            // a perfect fit for the order weight (3), but not allowed for the shipping method
            self::template(4, 'DK', 'DK', 3),
            // allowed, but they can't carry the order weight
            self::template(1, 'DK', 'DK', 1),
            self::template(6, 'DK', 'DK', 2),
            // allowed and the closest fit
            self::template(2, 'DK', 'DK', 5),
            // allowed, but a worse fit
            self::template(3, 'DK', 'DK', 10),
            // allowed, but for another receiver country
            self::template(5, 'DK', 'SE', 3),
        ]);

        $order = $this->order('DK', self::shippingMethod('GLS', [1, 2, 3, 5, 6]), [
            // items without a variant or a weight don't count, and must not stop the summation
            $this->itemWithoutVariant(),
            self::item(null, 4),
            // the order weight is the sum of quantity * unit weight: 2 * 0.5 + 2 * 1.0 = 3
            self::item(0.5, 2),
            self::item(1.0, 2),
        ]);

        self::assertSame(2, $this->map($order)->shipmentTemplateId);
    }

    /**
     * @test
     */
    public function it_maps_a_shipment_template_that_exactly_fits_the_order_weight(): void
    {
        $this->givenShipmentTemplates([
            self::template(1, 'DK', 'DK', 4),
            self::template(2, 'DK', 'DK', 5),
        ]);

        $order = $this->order('DK', self::shippingMethod('GLS', [1, 2]), [self::item(5.0, 1)]);

        self::assertSame(2, $this->map($order)->shipmentTemplateId);
    }

    /**
     * The shipping method's shipment templates say where the store ships from, also when its company is registered in
     * another country
     *
     * @test
     */
    public function it_chooses_among_shipment_templates_that_all_ship_from_another_country_than_denmark(): void
    {
        $this->givenShipmentTemplates([
            self::template(1, 'SE', 'SE', 5),
            self::template(2, 'SE', 'NO', 5),
        ]);

        $order = $this->order('SE', self::shippingMethod('PostNord', [1, 2]), [self::item(1.0, 1)], shopBillingCountry: 'DK');

        self::assertSame(1, $this->map($order)->shipmentTemplateId);
    }

    /**
     * Only the templates that ship to the shipping address's country decide where the order ships from
     *
     * @test
     *
     * @dataProvider shippingCountries
     */
    public function it_decides_the_sender_country_among_the_shipment_templates_that_ship_to_the_shipping_country(string $shippingCountry, int $shipmentTemplate): void
    {
        $this->givenShipmentTemplates([
            self::template(1, 'DK', 'DK', 5),
            self::template(2, 'SE', 'SE', 5),
        ]);

        $order = $this->order($shippingCountry, self::shippingMethod('GLS', [1, 2]), [self::item(1.0, 1)]);

        self::assertSame($shipmentTemplate, $this->map($order)->shipmentTemplateId);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function shippingCountries(): iterable
    {
        yield 'to Denmark' => ['DK', 1];
        yield 'to Sweden' => ['SE', 2];
    }

    /**
     * Shipment templates that ship to the shipping country from several countries: the channel's shop billing data tells
     * where the order ships from
     *
     * @test
     *
     * @dataProvider channels
     */
    public function it_chooses_by_the_channels_shop_billing_country_when_the_shipment_templates_ship_from_several_countries(?string $shopBillingCountry, bool $inChannel, ?int $shipmentTemplate): void
    {
        $this->givenShipmentTemplates([
            self::template(1, 'DK', 'DK', 5),
            self::template(2, 'SE', 'DK', 5),
        ]);

        $order = $this->order('DK', self::shippingMethod('GLS', [1, 2]), [self::item(1.0, 1)], $shopBillingCountry, $inChannel);

        self::assertSame($shipmentTemplate, $this->map($order)->shipmentTemplateId);
    }

    /**
     * @return iterable<string, array{?string, bool, ?int}>
     */
    public static function channels(): iterable
    {
        yield 'a channel billed in Sweden' => ['SE', true, 2];
        yield 'a channel without shop billing data' => [null, true, null];
        yield 'no channel' => [null, false, null];
    }

    /**
     * An upload run maps every order with the same mapper, and kernel.reset resets it between requests and messages
     *
     * @test
     */
    public function it_fetches_the_shipment_templates_once_until_it_is_reset(): void
    {
        $this->httpClient
            ->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))
            ->willReturn(ShipmondoApi::json([self::template(1, 'DK', 'DK', 5)]))
            ->shouldBeCalledTimes(2)
        ;
        $mapper = new ShipmentTemplateSalesOrderDataMapper(ShipmondoApi::client($this->httpClient->reveal()));
        $order = $this->order('DK', self::shippingMethod('GLS', [1]), [self::item(1.0, 1)]);

        $mapper->map($order, $salesOrder = new SalesOrderRequest());
        self::assertSame(1, $salesOrder->shipmentTemplateId);
        $mapper->map($order, $salesOrder = new SalesOrderRequest());
        self::assertSame(1, $salesOrder->shipmentTemplateId);

        $mapper->reset();
        $mapper->map($order, $salesOrder = new SalesOrderRequest());
        self::assertSame(1, $salesOrder->shipmentTemplateId);
    }

    /**
     * @test
     */
    public function it_does_not_map_a_shipment_template_when_none_of_the_allowed_templates_supports_the_shipment(): void
    {
        $this->givenShipmentTemplates([
            self::template(1, 'DK', 'DK', 5),
        ]);

        $order = $this->order('SE', self::shippingMethod('GLS', [1]), [self::item(1.0, 1)]);

        self::assertNull($this->map($order)->shipmentTemplateId);
    }

    /**
     * @test
     */
    public function it_does_not_map_a_shipment_template_when_no_allowed_template_can_carry_the_order(): void
    {
        $this->givenShipmentTemplates([
            self::template(1, 'DK', 'DK', 2),
        ]);

        $order = $this->order('DK', self::shippingMethod('GLS', [1]), [self::item(5.0, 1)]);

        self::assertNull($this->map($order)->shipmentTemplateId);
    }
    /**
     * @test
     */
    public function it_does_not_map_a_shipment_template_when_no_template_is_allowed_for_the_shipping_method(): void
    {
        $this->givenShipmentTemplates([
            self::template(1, 'DK', 'DK', 5),
        ]);

        $order = $this->order('DK', self::shippingMethod('GLS', [99]), [self::item(1.0, 1)]);

        self::assertNull($this->map($order)->shipmentTemplateId);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_has_no_shipping_country(): void
    {
        $this->httpClient->sendRequest(Argument::any())->shouldNotBeCalled();

        $order = $this->prophesize(OrderInterface::class);
        $order->getShippingAddress()->willReturn(null);

        self::assertNull($this->map($order->reveal())->shipmentTemplateId);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_has_no_shipments(): void
    {
        $this->httpClient->sendRequest(Argument::any())->shouldNotBeCalled();

        $order = $this->prophesize(OrderInterface::class);
        $order->getShippingAddress()->willReturn(self::address('DK'));
        $order->getShipments()->willReturn(new ArrayCollection());

        self::assertNull($this->map($order->reveal())->shipmentTemplateId);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_shipping_method_is_not_a_shipmondo_shipping_method(): void
    {
        $this->httpClient->sendRequest(Argument::any())->shouldNotBeCalled();

        $shippingMethod = new BaseShippingMethod();
        $shippingMethod->setCurrentLocale('en_US');
        $shippingMethod->setName('GLS');

        $shipment = new BaseShipment();
        $shipment->setMethod($shippingMethod);

        $order = $this->prophesize(OrderInterface::class);
        $order->getShippingAddress()->willReturn(self::address('DK'));
        $order->getShipments()->willReturn(new ArrayCollection([$shipment]));

        self::assertNull($this->map($order->reveal())->shipmentTemplateId);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_shipping_method_has_no_name(): void
    {
        $this->httpClient->sendRequest(Argument::any())->shouldNotBeCalled();

        $order = $this->order('DK', self::shippingMethod(null, [1]), [self::item(1.0, 1)]);

        self::assertNull($this->map($order)->shipmentTemplateId);
    }

    /**
     * @param list<array<string, mixed>> $templates
     */
    private function givenShipmentTemplates(array $templates): void
    {
        $this->httpClient
            ->sendRequest(ShipmondoApi::request('GET', 'shipment_templates'))
            ->willReturn(ShipmondoApi::json($templates))
            ->shouldBeCalledOnce()
        ;
    }

    /**
     * @return array<string, mixed>
     */
    private static function template(int $id, string $senderCountry, string $receiverCountry, int $parcelWeight): array
    {
        return [
            'id' => $id,
            'name' => sprintf('Template %d', $id),
            'sender' => ['country_code' => $senderCountry],
            'receiver' => ['country_code' => $receiverCountry],
            'parcels' => [['quantity' => 1, 'weight' => $parcelWeight]],
        ];
    }

    /**
     * @param list<int> $allowedShipmentTemplates
     */
    private static function shippingMethod(?string $name, array $allowedShipmentTemplates): ShippingMethod
    {
        $shippingMethod = new ShippingMethod();
        $shippingMethod->setCurrentLocale('en_US');
        $shippingMethod->setName($name);
        $shippingMethod->setAllowedShipmentTemplates($allowedShipmentTemplates);

        return $shippingMethod;
    }

    private function item(?float $weight, int $quantity): OrderItemInterface
    {
        $variant = new ProductVariant();
        $variant->setWeight($weight);

        $item = $this->prophesize(OrderItemInterface::class);
        $item->getVariant()->willReturn($variant);
        $item->getQuantity()->willReturn($quantity);

        return $item->reveal();
    }

    private function itemWithoutVariant(): OrderItemInterface
    {
        $item = $this->prophesize(OrderItemInterface::class);
        $item->getVariant()->willReturn(null);
        $item->getQuantity()->willReturn(1);

        return $item->reveal();
    }

    private static function address(string $countryCode): Address
    {
        $address = new Address();
        $address->setCountryCode($countryCode);

        return $address;
    }

    /**
     * @param list<OrderItemInterface> $items
     */
    private function order(string $countryCode, ShippingMethod $shippingMethod, array $items, ?string $shopBillingCountry = null, bool $inChannel = true): OrderInterface
    {
        $shipment = new Shipment();
        $shipment->setMethod($shippingMethod);

        $channel = $inChannel ? new Channel() : null;
        if (null !== $channel && null !== $shopBillingCountry) {
            $shopBillingData = new ShopBillingData();
            $shopBillingData->setCountryCode($shopBillingCountry);
            $channel->setShopBillingData($shopBillingData);
        }

        $order = $this->prophesize(OrderInterface::class);
        $order->getChannel()->willReturn($channel);
        $order->getShippingAddress()->willReturn(self::address($countryCode));
        $order->getShipments()->willReturn(new ArrayCollection([$shipment]));
        $order->getItems()->willReturn(new ArrayCollection($items));

        return $order->reveal();
    }

    private function map(OrderInterface $order): SalesOrderRequest
    {
        $salesOrder = new SalesOrderRequest();

        (new ShipmentTemplateSalesOrderDataMapper(ShipmondoApi::client($this->httpClient->reveal())))->map($order, $salesOrder);

        return $salesOrder;
    }
}
