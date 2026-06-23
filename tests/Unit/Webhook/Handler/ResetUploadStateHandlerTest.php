<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\Handler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Setono\Shipmondo\Enum\WebhookAction;
use Setono\Shipmondo\Enum\WebhookResourceName;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Webhook\Handler\ResetUploadStateHandler;
use Setono\SyliusShipmondoPlugin\Webhook\OrderResolverInterface;
use Setono\SyliusShipmondoPlugin\Webhook\RemoteEvent;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\WorkflowInterface;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;

final class ResetUploadStateHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<OrderResolverInterface> */
    private ObjectProphecy $orderResolver;

    /** @var ObjectProphecy<WorkflowInterface> */
    private ObjectProphecy $orderWorkflow;

    /** @var ObjectProphecy<ManagerRegistry> */
    private ObjectProphecy $managerRegistry;

    /** @var ObjectProphecy<EntityManagerInterface> */
    private ObjectProphecy $entityManager;

    protected function setUp(): void
    {
        $this->orderResolver = $this->prophesize(OrderResolverInterface::class);
        $this->orderWorkflow = $this->prophesize(WorkflowInterface::class);
        $this->managerRegistry = $this->prophesize(ManagerRegistry::class);
        $this->entityManager = $this->prophesize(EntityManagerInterface::class);
    }

    /**
     * @test
     */
    public function it_resets_the_upload_state_and_clears_the_shipmondo_id_on_delete(): void
    {
        // real captured payload of a deleted/archived Shipmondo sales order
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->prophesize(OrderInterface::class);
        $order->setShipmondoId(null)->shouldBeCalled();
        $orderRevealed = $order->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($orderRevealed);

        $this->orderWorkflow->can($orderRevealed, OrderWorkflow::TRANSITION_RESET)->willReturn(true);
        $this->orderWorkflow->apply($orderRevealed, OrderWorkflow::TRANSITION_RESET)->willReturn(new Marking())->shouldBeCalled();
        $this->expectFlush();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);
    }

    /**
     * @test
     */
    public function it_does_nothing_for_a_non_delete_action(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->expectNoInteraction();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::StatusUpdate);
    }

    /**
     * @test
     */
    public function it_does_nothing_for_another_resource(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->expectNoInteraction();

        $this->handle($payload, WebhookResourceName::Shipments, WebhookAction::Cancel);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_order_cannot_be_resolved(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $this->orderResolver->resolveFromPayload($payload)->willReturn(null);
        $this->orderWorkflow->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_the_upload_state_cannot_be_reset(): void
    {
        $payload = WebhookPayloadFixtures::load('orders_delete');
        $order = $this->prophesize(OrderInterface::class);
        $order->setShipmondoId(Argument::any())->shouldNotBeCalled();
        $orderRevealed = $order->reveal();
        $this->orderResolver->resolveFromPayload($payload)->willReturn($orderRevealed);

        $this->orderWorkflow->can($orderRevealed, OrderWorkflow::TRANSITION_RESET)->willReturn(false);
        $this->orderWorkflow->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();

        $this->handle($payload, WebhookResourceName::Orders, WebhookAction::Delete);
    }

    private function expectFlush(): void
    {
        $this->managerRegistry->getManagerForClass(Argument::any())->willReturn($this->entityManager->reveal());
        $this->entityManager->flush()->shouldBeCalled();
    }

    private function expectNoInteraction(): void
    {
        $this->orderResolver->resolveFromPayload(Argument::any())->shouldNotBeCalled();
        $this->orderWorkflow->apply(Argument::cetera())->shouldNotBeCalled();
        $this->managerRegistry->getManagerForClass(Argument::any())->shouldNotBeCalled();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handle(array $payload, WebhookResourceName $resource, WebhookAction $action): void
    {
        $handler = new ResetUploadStateHandler(
            $this->orderResolver->reveal(),
            $this->orderWorkflow->reveal(),
            $this->managerRegistry->reveal(),
        );

        $handler->handle(new RemoteEvent('shipmondo.event', $payload, $resource, $action));
    }
}
