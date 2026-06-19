<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\RemoteEvent\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\PaymentStateHandler;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

final class PaymentStateHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
    }

    /**
     * @test
     */
    public function it_completes_the_payments_on_payment_captured(): void
    {
        // real captured (marked_as_paid) sales-order payload
        $payload = WebhookPayloadFixtures::load('orders_payment_captured');

        $payment = $this->prophesize(PaymentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getPayments()->willReturn(new ArrayCollection([$payment]));
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order->reveal());

        $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)->willReturn(true);
        $this->stateMachine->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)->shouldBeCalled();
        $this->expectFlush();

        $this->handle($payload, 'orders', 'payment_captured');
    }

    /**
     * @test
     */
    public function it_cancels_the_order_payment_state_on_payment_voided(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_payment_captured');

        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order);

        $this->stateMachine->can($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)->willReturn(true);
        $this->stateMachine->apply($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)->shouldBeCalled();
        $this->expectFlush();

        $this->handle($payload, 'orders', 'payment_voided');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_payment_action(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_payment_captured');
        $this->expectNoTransition();

        $this->handle($payload, 'orders', 'payment_refunded');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_resource(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_payment_captured');
        $this->expectNoTransition();

        $this->handle($payload, 'shipments', 'create');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_cannot_be_resolved(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_payment_captured');
        $this->orderResolver->resolveFromPayload($payload)->willReturn(null);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, 'orders', 'payment_captured');
    }

    /**
     * @test
     */
    public function it_does_not_complete_when_no_payment_can_be_completed(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_payment_captured');

        $payment = $this->prophesize(PaymentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getPayments()->willReturn(new ArrayCollection([$payment]));
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order->reveal());

        $this->stateMachine->can($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, 'orders', 'payment_captured');
    }

    /**
     * @test
     */
    public function it_does_not_void_when_the_order_payment_cannot_be_cancelled(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_payment_captured');

        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order);
        $this->stateMachine->can($order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, 'orders', 'payment_voided');
    }

    private function expectFlush(): void
    {
        $this->managerRegistry->getManagerForClass(Argument::any())->willReturn($this->entityManager->reveal());
        $this->entityManager->flush()->shouldBeCalled();
    }

    private function expectNoTransition(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->shouldNotBeCalled();
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(array $payload, string $resource, string $action): void
    {
        $handler = new PaymentStateHandler(
            $this->orderResolver->reveal(),
            $this->stateMachine->reveal(),
            $this->managerRegistry->reveal(),
        );

        $handler->handle(new RemoteEvent('shipmondo.event', $payload, $resource, $action));
    }
}
