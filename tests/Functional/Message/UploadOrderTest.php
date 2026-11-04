<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Message;

use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\UploadOutcome;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;

/**
 * The UploadOrder command handled on its own, as an asynchronous worker handles it: outside an upload run, for an
 * order an upload run already put in uploading
 */
final class UploadOrderTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_uploads_the_order_and_returns_the_outcome(): void
    {
        $order = $this->queuedOrder();
        $this->shipmondo
            ->respond('GET', 'shipment_templates', 200, [])
            ->respond('POST', 'sales_orders', 201, ['id' => 1001])
        ;

        self::assertSame(UploadOutcome::Uploaded, $this->dispatch(new UploadOrder($order)));

        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$row['shipmondo_state'], $row['shipmondo_id']]);
    }

    /**
     * @test
     */
    public function it_settles_the_order_when_the_upload_fails_instead_of_throwing_for_messenger_to_retry(): void
    {
        $order = $this->queuedOrder();
        $this->shipmondo
            ->respond('GET', 'shipment_templates', 200, [])
            ->respond('POST', 'sales_orders', 422, ['error' => 'The payment gateway does not exist'])
        ;

        self::assertSame(UploadOutcome::Rejected, $this->dispatch(new UploadOrder($order)));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($order))['shipmondo_state']);
    }

    /**
     * @test
     */
    public function it_sends_an_order_that_changed_after_it_was_queued_back_to_pending_without_uploading_it(): void
    {
        $order = $this->queuedOrder();
        $version = $order->getVersion();
        self::assertIsInt($version);
        $queuedVersion = $version - 1;

        self::assertSame(UploadOutcome::ChangedSinceQueued, $this->dispatch(new UploadOrder(self::id($order), $queuedVersion)));

        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->orderRow(self::id($order))['shipmondo_state']);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * @test
     */
    public function it_leaves_an_order_alone_that_is_already_uploaded(): void
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());
        $this->fixtures->markAsUploaded($order, 999);

        self::assertSame(UploadOutcome::Skipped, $this->dispatch(new UploadOrder($order)));

        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 999], [$row['shipmondo_state'], $row['shipmondo_id']]);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * An order an upload run put in uploading and queued for upload
     */
    private function queuedOrder(): OrderInterface
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());
        $order->setShipmondoState(OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO);
        $this->entityManager()->flush();

        return $order;
    }

    private function dispatch(UploadOrder $message): mixed
    {
        $commandBus = static::getContainer()->get('setono_sylius_shipmondo.command_bus');
        self::assertInstanceOf(MessageBusInterface::class, $commandBus);

        return $commandBus->dispatch($message)->last(HandledStamp::class)?->getResult();
    }
}
