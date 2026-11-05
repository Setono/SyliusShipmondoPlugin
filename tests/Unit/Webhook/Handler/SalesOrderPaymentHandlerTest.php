<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\SalesOrderPaymentHandler;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderPaymentTransitions;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

final class SalesOrderPaymentHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    private PaymentInterface $payment;

    private OrderInterface $order;

    protected function setUp(): void
    {
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);

        $this->payment = $this->prophesize(PaymentInterface::class)->reveal();
        $order = $this->prophesize(OrderInterface::class);
        $order->getPayments()->willReturn(new ArrayCollection([$this->payment]));
        $this->order = $order->reveal();
    }

    /**
     * @test
     */
    public function it_handles_the_payment_webhooks(): void
    {
        self::assertSame([WebhookAction::PaymentCaptured, WebhookAction::PaymentVoided], SalesOrderPaymentHandler::getSubscribedActions());
    }

    /**
     * @test
     */
    public function it_completes_the_payments_on_payment_captured(): void
    {
        $this->stateMachine->can($this->payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)->willReturn(true);
        $this->stateMachine->apply($this->payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)->shouldBeCalled();
        $this->stateMachine->apply($this->order, Argument::cetera())->shouldNotBeCalled();

        $this->handle(WebhookAction::PaymentCaptured);
    }

    /**
     * @test
     */
    public function it_cancels_the_order_payment_state_on_payment_voided(): void
    {
        $this->stateMachine->can($this->order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)->willReturn(true);
        $this->stateMachine->apply($this->order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)->shouldBeCalled();
        // cancelling the payment would make Sylius spawn a replacement payment
        $this->stateMachine->apply($this->payment, Argument::cetera())->shouldNotBeCalled();

        $this->handle(WebhookAction::PaymentVoided);
    }

    /**
     * @test
     */
    public function it_does_not_complete_when_no_payment_can_be_completed(): void
    {
        $this->stateMachine->can($this->payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_COMPLETE)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();

        $this->handle(WebhookAction::PaymentCaptured);
    }

    /**
     * @test
     */
    public function it_does_not_void_when_the_order_payment_cannot_be_cancelled(): void
    {
        $this->stateMachine->can($this->order, OrderPaymentTransitions::GRAPH, OrderPaymentTransitions::TRANSITION_CANCEL)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();

        $this->handle(WebhookAction::PaymentVoided);
    }

    private function handle(WebhookAction $action): void
    {
        // real captured (marked_as_paid) sales-order payload
        $event = new RemoteEvent('shipmondo.event', WebhookPayloadFixtures::load('orders_payment_captured'), WebhookResourceName::Orders, $action);

        (new SalesOrderPaymentHandler($this->stateMachine->reveal()))->handle($this->order, $event);
    }
}
