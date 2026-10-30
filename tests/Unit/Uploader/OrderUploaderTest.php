<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Uploader;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Provider\PreQualifiedUploadableOrdersProviderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploader;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\Exception\LogicException;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\WorkflowInterface;

final class OrderUploaderTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_starts_the_upload_and_dispatches_an_upload_command_for_each_eligible_order(): void
    {
        // the first order can't start the upload, the second one was changed concurrently and the third one is uploaded
        $notTransitionable = $this->order(1, 1);
        $concurrentlyChanged = $this->order(2, 4);
        $uploadable = $this->order(3, 9);

        $provider = $this->prophesize(PreQualifiedUploadableOrdersProviderInterface::class);
        $provider->getOrders()->willReturn([$notTransitionable, $concurrentlyChanged, $uploadable]);

        $workflow = $this->prophesize(WorkflowInterface::class);
        $workflow->apply($notTransitionable, OrderWorkflow::TRANSITION_START_UPLOAD)
            ->willThrow(new LogicException('Transition "start_upload" is not enabled'))
            ->shouldBeCalledOnce()
        ;
        $workflow->apply($concurrentlyChanged, OrderWorkflow::TRANSITION_START_UPLOAD)->willReturn(new Marking())->shouldBeCalledOnce();
        $workflow->apply($uploadable, OrderWorkflow::TRANSITION_START_UPLOAD)->willReturn(new Marking())->shouldBeCalledOnce();

        $flushes = 0;
        $entityManager = $this->prophesize(EntityManagerInterface::class);
        $entityManager->flush()->will(static function () use (&$flushes, $concurrentlyChanged): void {
            if (1 === ++$flushes) {
                throw OptimisticLockException::lockFailed($concurrentlyChanged);
            }
        });

        $managerRegistry = $this->prophesize(ManagerRegistry::class);
        $managerRegistry->getManagerForClass(Argument::type('string'))->willReturn($entityManager->reveal());

        $commandBus = $this->prophesize(MessageBusInterface::class);
        $commandBus->dispatch(Argument::that(
            static fn (object $message): bool => $message instanceof UploadOrder && 3 === $message->order && 9 === $message->version,
        ))->willReturn(new Envelope(new \stdClass()))->shouldBeCalledOnce();
        $commandBus->dispatch(Argument::that(
            static fn (object $message): bool => $message instanceof UploadOrder && 3 !== $message->order,
        ))->shouldNotBeCalled();

        $uploader = new OrderUploader($provider->reveal(), $commandBus->reveal(), $workflow->reveal(), $managerRegistry->reveal());
        $uploader->upload();

        self::assertSame(2, $flushes);
    }

    private function order(int $id, int $version): OrderInterface
    {
        $order = $this->prophesize(OrderInterface::class);
        $order->getId()->willReturn($id);
        $order->getVersion()->willReturn($version);

        return $order->reveal();
    }
}
