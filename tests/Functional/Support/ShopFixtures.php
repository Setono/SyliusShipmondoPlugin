<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectRepository;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Model\PaymentMethodInterface;
use Setono\SyliusShipmondoPlugin\Model\ShipmentInterface;
use Setono\SyliusShipmondoPlugin\Model\ShippingMethodInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Addressing\Model\ZoneMemberInterface;
use Sylius\Component\Core\Factory\PaymentMethodFactoryInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ChannelPricingInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Sylius\Component\Product\Factory\ProductFactoryInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Shipping\Calculator\DefaultCalculators;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webmozart\Assert\Assert;

/**
 * Builds a minimal but real Sylius shop (one channel, one product, one shipping and one payment method) and
 * real orders for it, using Sylius's own factories and its checkout state machine — the same steps Sylius's
 * order fixtures take — so the orders look exactly like orders placed through the shop.
 *
 * The shop and its administrator are the baseline every test starts from: {@see self::createBaseline()} commits
 * them once per run, and {@see self::load()} loads them into the entity manager of the test's kernel.
 */
final class ShopFixtures
{
    public const CHANNEL_CODE = 'WEB';

    public const LOCALE_CODE = 'en_US';

    public const CURRENCY_CODE = 'USD';

    public const COUNTRY_CODE = 'DK';

    public const SHIPPING_METHOD_CODE = 'gls_pickup_point';

    public const PAYMENT_METHOD_CODE = 'bank_transfer';

    public const PAYMENT_METHOD_SHIPMONDO_ID = 4711;

    public const PRODUCT_PRICE = 2500;

    public const SHIPPING_FEE = 500;

    public const ADMIN_EMAIL = 'admin@example.com';

    public ChannelInterface $channel;

    public ShippingMethodInterface $shippingMethod;

    public PaymentMethodInterface $paymentMethod;

    public ProductVariantInterface $variant;

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * Loads the baseline shop into the current entity manager
     */
    public function load(): self
    {
        $this->channel = $this->findOneBy('sylius.repository.channel', ChannelInterface::class, ['code' => self::CHANNEL_CODE]);
        $this->shippingMethod = $this->findOneBy('sylius.repository.shipping_method', ShippingMethodInterface::class, ['code' => self::SHIPPING_METHOD_CODE]);
        $this->paymentMethod = $this->findOneBy('sylius.repository.payment_method', PaymentMethodInterface::class, ['code' => self::PAYMENT_METHOD_CODE]);
        $this->variant = $this->findOneBy('sylius.repository.product_variant', ProductVariantInterface::class, ['code' => 'T_SHIRT_BLUE']);

        return $this;
    }

    /**
     * The baseline administrator
     */
    public function admin(): AdminUserInterface
    {
        return $this->findOneBy('sylius.repository.admin_user', AdminUserInterface::class, ['email' => self::ADMIN_EMAIL]);
    }

    /**
     * Creates the shop and its administrator
     */
    public function createBaseline(): void
    {
        $this->createShop();
        $this->createAdmin();
    }

    private function createShop(): void
    {
        $locale = $this->create('sylius.factory.locale', LocaleInterface::class);
        $locale->setCode(self::LOCALE_CODE);

        $currency = $this->create('sylius.factory.currency', CurrencyInterface::class);
        $currency->setCode(self::CURRENCY_CODE);

        $country = $this->create('sylius.factory.country', CountryInterface::class);
        $country->setCode(self::COUNTRY_CODE);

        $zone = $this->create('sylius.factory.zone', ZoneInterface::class);
        $zone->setCode(self::COUNTRY_CODE);
        $zone->setName('Denmark');
        $zone->setType(ZoneInterface::TYPE_COUNTRY);
        $zoneMember = $this->create('sylius.factory.zone_member', ZoneMemberInterface::class);
        $zoneMember->setCode(self::COUNTRY_CODE);
        $zone->addMember($zoneMember);

        $channel = $this->create('sylius.factory.channel', ChannelInterface::class);
        $channel->setCode(self::CHANNEL_CODE);
        $channel->setName('Web store');
        $channel->setHostname('localhost');
        $channel->setEnabled(true);
        $channel->setBaseCurrency($currency);
        $channel->addCurrency($currency);
        $channel->setDefaultLocale($locale);
        $channel->addLocale($locale);
        $channel->setDefaultTaxZone($zone);
        $channel->setTaxCalculationStrategy('order_items_based');
        $channel->setAccountVerificationRequired(false);
        $this->channel = $channel;

        $shippingMethod = $this->create('sylius.factory.shipping_method', ShippingMethodInterface::class);
        $shippingMethod->setCode(self::SHIPPING_METHOD_CODE);
        $shippingMethod->setCurrentLocale(self::LOCALE_CODE);
        $shippingMethod->setFallbackLocale(self::LOCALE_CODE);
        $shippingMethod->setName('GLS pickup point');
        $shippingMethod->setZone($zone);
        $shippingMethod->setCalculator(DefaultCalculators::FLAT_RATE);
        $shippingMethod->setConfiguration([self::CHANNEL_CODE => ['amount' => self::SHIPPING_FEE]]);
        $shippingMethod->addChannel($channel);
        $shippingMethod->setEnabled(true);
        $shippingMethod->setPickupPointDelivery(true);
        $shippingMethod->setCarrierCode('gls');
        $this->shippingMethod = $shippingMethod;

        $paymentMethodFactory = $this->service('sylius.factory.payment_method', PaymentMethodFactoryInterface::class);
        $paymentMethod = $paymentMethodFactory->createWithGateway('offline');
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        $paymentMethod->getGatewayConfig()?->setGatewayName('offline');
        $paymentMethod->setCode(self::PAYMENT_METHOD_CODE);
        $paymentMethod->setCurrentLocale(self::LOCALE_CODE);
        $paymentMethod->setFallbackLocale(self::LOCALE_CODE);
        $paymentMethod->setName('Bank transfer');
        $paymentMethod->addChannel($channel);
        $paymentMethod->setEnabled(true);
        $paymentMethod->setShipmondoId(self::PAYMENT_METHOD_SHIPMONDO_ID);
        $this->paymentMethod = $paymentMethod;

        $productFactory = $this->service('sylius.factory.product', ProductFactoryInterface::class);
        $product = $productFactory->createWithVariant();
        Assert::isInstanceOf($product, ProductInterface::class);
        $product->setCode('T_SHIRT');
        $product->setCurrentLocale(self::LOCALE_CODE);
        $product->setFallbackLocale(self::LOCALE_CODE);
        $product->setName('T-shirt');
        $product->setSlug('t-shirt');
        $product->addChannel($channel);

        $variant = $product->getVariants()->first();
        Assert::isInstanceOf($variant, ProductVariantInterface::class);
        $variant->setCode('T_SHIRT_BLUE');
        $variant->setCurrentLocale(self::LOCALE_CODE);
        $variant->setFallbackLocale(self::LOCALE_CODE);
        $variant->setName('Blue');
        $variant->setWeight(250);

        $channelPricing = $this->create('sylius.factory.channel_pricing', ChannelPricingInterface::class);
        $channelPricing->setChannelCode(self::CHANNEL_CODE);
        $channelPricing->setPrice(self::PRODUCT_PRICE);
        $variant->addChannelPricing($channelPricing);
        $this->variant = $variant;

        $this->persist($locale, $currency, $country, $zone, $channel, $shippingMethod, $paymentMethod, $product);
    }

    private function createAdmin(): void
    {
        $admin = $this->create('sylius.factory.admin_user', AdminUserInterface::class);
        $admin->setEmail(self::ADMIN_EMAIL);
        $admin->setUsername('admin');
        $admin->setPlainPassword('admin');
        $admin->setEnabled(true);
        $admin->setLocaleCode(self::LOCALE_CODE);

        $this->persist($admin);
    }

    public function createCustomer(string $email = 'customer@example.com'): CustomerInterface
    {
        $customer = $this->create('sylius.factory.customer', CustomerInterface::class);
        $customer->setEmail($email);
        $customer->setFirstName('Jane');
        $customer->setLastName('Doe');

        $this->persist($customer);

        return $customer;
    }

    public function createShopUser(CustomerInterface $customer): ShopUserInterface
    {
        $shopUser = $this->create('sylius.factory.shop_user', ShopUserInterface::class);
        $shopUser->setCustomer($customer);
        $shopUser->setUsername((string) $customer->getEmail());
        $shopUser->setPlainPassword('secret');
        $shopUser->setEnabled(true);

        $this->persist($shopUser);

        return $shopUser;
    }

    /**
     * A cart with two t-shirts and a shipping address, i.e. a customer in the middle of the checkout
     */
    public function createCart(CustomerInterface $customer): OrderInterface
    {
        $order = $this->create('sylius.factory.order', OrderInterface::class);
        $order->setChannel($this->channel);
        $order->setCustomer($customer);
        $order->setCurrencyCode(self::CURRENCY_CODE);
        $order->setLocaleCode(self::LOCALE_CODE);
        // a cart started by a logged-in customer, which is what lets Sylius find it for that customer again
        $order->setCreatedByGuest(null === $customer->getUser());

        $item = $this->create('sylius.factory.order_item', OrderItemInterface::class);
        $item->setVariant($this->variant);
        $this->service('sylius.order_item_quantity_modifier', OrderItemQuantityModifierInterface::class)->modify($item, 2);
        $order->addItem($item);

        $order->setShippingAddress($this->address());
        $order->setBillingAddress($this->address());

        $this->stateMachine()->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_ADDRESS);

        $this->persist($order);

        return $order;
    }

    /**
     * A placed order (checkout completed) whose payment is completed ($paid), authorized ($authorized) or still
     * awaiting payment, optionally delivered to a chosen pickup point
     *
     * @param array<string, mixed>|null $pickupPoint
     */
    public function createOrder(
        CustomerInterface $customer,
        bool $paid = true,
        bool $authorized = false,
        ?array $pickupPoint = null,
    ): OrderInterface {
        $order = $this->createCart($customer);

        foreach ($order->getShipments() as $shipment) {
            $shipment->setMethod($this->shippingMethod);
            if ($shipment instanceof ShipmentInterface) {
                $shipment->setShipmondoPickupPoint($pickupPoint);
            }
        }
        $this->stateMachine()->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_SHIPPING);

        foreach ($order->getPayments() as $payment) {
            $payment->setMethod($this->paymentMethod);
        }
        $this->stateMachine()->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_SELECT_PAYMENT);

        $order->setNotes('Please leave the parcel at the door');
        $this->stateMachine()->apply($order, OrderCheckoutTransitions::GRAPH, OrderCheckoutTransitions::TRANSITION_COMPLETE);

        $paymentTransition = match (true) {
            $paid => PaymentTransitions::TRANSITION_COMPLETE,
            $authorized => PaymentTransitions::TRANSITION_AUTHORIZE,
            default => null,
        };

        if (null !== $paymentTransition) {
            foreach ($order->getPayments() as $payment) {
                $this->stateMachine()->apply($payment, PaymentTransitions::GRAPH, $paymentTransition);
            }
        }

        $this->persist($order);

        return $order;
    }

    /**
     * Marks the order as uploaded to Shipmondo, as UploadOrderHandler does after a successful upload
     */
    public function markAsUploaded(OrderInterface $order, int $shipmondoId): void
    {
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO);
        $order->setShipmondoId($shipmondoId);

        $this->persist($order);
    }

    private function address(): AddressInterface
    {
        $address = $this->create('sylius.factory.address', AddressInterface::class);
        $address->setFirstName('Jane');
        $address->setLastName('Doe');
        $address->setStreet('Vestergade 1');
        $address->setPostcode('8000');
        $address->setCity('Aarhus');
        $address->setCountryCode(self::COUNTRY_CODE);
        $address->setPhoneNumber('+4512345678');

        return $address;
    }

    private function persist(object ...$entities): void
    {
        $entityManager = $this->service('doctrine.orm.entity_manager', EntityManagerInterface::class);
        foreach ($entities as $entity) {
            $entityManager->persist($entity);
        }

        $entityManager->flush();
    }

    private function stateMachine(): StateMachineInterface
    {
        return $this->service('sylius_abstraction.state_machine', StateMachineInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function create(string $factoryId, string $class): object
    {
        $object = $this->service($factoryId, FactoryInterface::class)->createNew();
        if (!$object instanceof $class) {
            throw new \UnexpectedValueException(sprintf('The factory "%s" did not create a %s', $factoryId, $class));
        }

        return $object;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     * @param array<string, mixed> $criteria
     *
     * @return T
     */
    private function findOneBy(string $repositoryId, string $class, array $criteria): object
    {
        $entity = $this->service($repositoryId, ObjectRepository::class)->findOneBy($criteria);
        if (!$entity instanceof $class) {
            throw new \UnexpectedValueException(sprintf('The baseline %s matching %s does not exist', $class, (string) json_encode($criteria)));
        }

        return $entity;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = $this->container->get($id);
        if (!$service instanceof $class) {
            throw new \UnexpectedValueException(sprintf('The service "%s" is not a %s', $id, $class));
        }

        return $service;
    }
}
