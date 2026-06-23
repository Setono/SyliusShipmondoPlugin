<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\CancelOrderHandler;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\WorkflowInterface;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

final class CancelOrderHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<StateMachineInterface> */
    private ObjectProphecy $stateMachine;

    /** @var ObjectProphecy<WorkflowInterface> */
    private ObjectProphecy $orderWorkflow;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->stateMachine = $this->prophesize(StateMachineInterface::class);
        $this->orderWorkflow = $this->prophesize(WorkflowInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
    }

    /**
     * @test
     */
    public function it_cancels_the_order_and_resets_its_upload_state_on_delete(): void
    {
        // real captured payload of a deleted/archived Shipmondo sales order
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order);

        $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->willReturn(true);
        $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->shouldBeCalled();
        $this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)->willReturn(true);
        $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_RESET)->willReturn(new Marking())->shouldBeCalled();
        $this->expectFlush();

        $this->handle($payload, 'orders', 'delete');
    }

    /**
     * @test
     */
    public function it_resets_the_upload_state_even_when_the_order_cannot_be_cancelled(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order);

        $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)->willReturn(true);
        $this->orderWorkflow->apply($order, OrderWorkflow::TRANSITION_RESET)->willReturn(new Marking())->shouldBeCalled();
        $this->expectFlush();

        $this->handle($payload, 'orders', 'delete');
    }

    /**
     * @test
     */
    public function it_cancels_the_order_even_when_the_upload_state_cannot_be_reset(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order);

        $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->willReturn(true);
        $this->stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->shouldBeCalled();
        $this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)->willReturn(false);
        $this->orderWorkflow->apply(Argument::cetera())->shouldNotBeCalled();
        $this->expectFlush();

        $this->handle($payload, 'orders', 'delete');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_neither_transition_is_allowed(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($order);

        $this->stateMachine->can($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL)->willReturn(false);
        $this->orderWorkflow->can($order, OrderWorkflow::TRANSITION_RESET)->willReturn(false);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderWorkflow->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, 'orders', 'delete');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_a_non_delete_action(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->expectNoInteraction();

        $this->handle($payload, 'orders', 'status_update');
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_resource(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->expectNoInteraction();

        $this->handle($payload, 'shipments', 'cancel');
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_cannot_be_resolved(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->orderResolver->resolveFromPayload($payload)->willReturn(null);
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderWorkflow->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, 'orders', 'delete');
    }

    private function expectFlush(): void
    {
        $this->managerRegistry->getManagerForClass(Argument::any())->willReturn($this->entityManager->reveal());
        $this->entityManager->flush()->shouldBeCalled();
    }

    private function expectNoInteraction(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->shouldNotBeCalled();
        $this->stateMachine->apply(Argument::cetera())->shouldNotBeCalled();
        $this->orderWorkflow->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(array $payload, string $resource, string $action): void
    {
        $handler = new CancelOrderHandler(
            $this->orderResolver->reveal(),
            $this->stateMachine->reveal(),
            $this->orderWorkflow->reveal(),
            $this->managerRegistry->reveal(),
        );

        $handler->handle(new RemoteEvent('shipmondo.event', $payload, $resource, $action));
    }
}
