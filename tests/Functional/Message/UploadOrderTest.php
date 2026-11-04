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
        $order = $this->uploadingOrder();
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
     *
     * @dataProvider failedUploads
     *
     * @param \Closure(self, OrderInterface): mixed $fail
     */
    public function it_settles_the_order_when_the_upload_fails_instead_of_throwing_for_messenger_to_retry(
        \Closure $fail,
        UploadOutcome $outcome,
        string $state,
    ): void {
        $order = $this->uploadingOrder();
        $this->shipmondo->respond('GET', 'shipment_templates', 200, []);
        $fail($this, $order);

        self::assertSame($outcome, $this->dispatch(new UploadOrder($order)));

        $row = $this->orderRow(self::id($order));
        self::assertSame([$state, null], [$row['shipmondo_state'], $row['shipmondo_id']]);
    }

    /**
     * @return iterable<string, array{\Closure(self, OrderInterface): mixed, UploadOutcome, string}>
     */
    public static function failedUploads(): iterable
    {
        $answer = static fn (int $statusCode): \Closure => static fn (self $test): mixed => $test->shipmondo->respond('POST', 'sales_orders', $statusCode, ['error' => 'No']);

        yield 'rejected upload (422)' => [$answer(422), UploadOutcome::Rejected, OrderInterface::SHIPMONDO_STATE_FAILED];
        yield 'unexpected failure (a data mapper throws)' => [
            static function (self $test, OrderInterface $order): void {
                $test->failToMap($order);
            },
            UploadOutcome::UnexpectedFailure,
            OrderInterface::SHIPMONDO_STATE_FAILED,
        ];
        yield 'credentials failure (401)' => [$answer(401), UploadOutcome::CredentialsFailure, OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'credentials failure (403)' => [$answer(403), UploadOutcome::CredentialsFailure, OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'rate-limited upload (429)' => [$answer(429), UploadOutcome::RateLimited, OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'temporary failure (500)' => [$answer(500), UploadOutcome::TemporaryFailure, OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'temporary failure (network)' => [
            static fn (self $test): mixed => $test->shipmondo->failToConnect('POST', 'sales_orders'),
            UploadOutcome::TemporaryFailure,
            OrderInterface::SHIPMONDO_STATE_PENDING,
        ];
    }

    /**
     * @test
     */
    public function it_sends_an_order_that_changed_while_it_was_uploading_back_to_pending_without_uploading_it(): void
    {
        $order = $this->uploadingOrder();
        $version = $order->getVersion();
        self::assertIsInt($version);

        // the command carries the order's version from when its upload started; the order has changed since
        $message = new UploadOrder(self::id($order), $version - 1);

        self::assertSame(UploadOutcome::ChangedWhileUploading, $this->dispatch($message));

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
     * An order an upload run put in uploading, whose UploadOrder command a worker hasn't handled yet
     */
    private function uploadingOrder(): OrderInterface
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
