<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Message;

use Setono\Shipmondo\Exception\InternalServerErrorException;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;

/**
 * A worker handling a DeleteOrder command it received from a transport, with no transaction around the bus's own: a
 * failure gets through, so Messenger retries it or sends it to the failure transport. It runs with real commits, since
 * DAMA's transaction would be an outer one.
 */
final class DeleteOrderTest extends FunctionalTestCase
{
    private const SHIPMONDO_ID = 123456;

    protected static function commitsForReal(): bool
    {
        return true;
    }

    /**
     * @test
     */
    public function a_worker_lets_a_failure_expected_to_pass_through_for_messenger_to_retry(): void
    {
        $order = $this->uploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 500, ['error' => 'Internal Server Error']);

        try {
            $this->handleAsAWorker($order);
            self::fail('The failure did not get through');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(InternalServerErrorException::class, array_values($e->getWrappedExceptions())[0] ?? null);
        }

        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, self::SHIPMONDO_ID], [$row['shipmondo_state'], $row['shipmondo_id']]);
    }

    /**
     * @test
     *
     * @dataProvider failuresTryingAgainWontFix
     */
    public function a_worker_sends_a_failure_that_trying_again_will_not_change_to_the_failure_transport(int $statusCode): void
    {
        $order = $this->uploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, $statusCode, ['error' => 'No']);

        try {
            $this->handleAsAWorker($order);
            self::fail('The failure did not get through');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(UnrecoverableMessageHandlingException::class, array_values($e->getWrappedExceptions())[0] ?? null);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function failuresTryingAgainWontFix(): iterable
    {
        yield 'credentials failure' => [401];
        yield 'rejected' => [422];
    }

    /**
     * @test
     */
    public function a_worker_unlinks_the_order_when_the_sales_order_was_already_deleted_in_shipmondo(): void
    {
        $order = $this->uploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 404, ['error' => 'Not found']);

        $this->handleAsAWorker($order);

        // the order isn't cancelled in Sylius, so it goes back to pending, to be uploaded again
        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$row['shipmondo_state'], $row['shipmondo_id']]);
    }

    private function uploadedOrder(): OrderInterface
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());
        $this->fixtures->markAsUploaded($order, self::SHIPMONDO_ID);

        return $order;
    }

    private function handleAsAWorker(OrderInterface $order): void
    {
        $commandBus = static::getContainer()->get('setono_sylius_shipmondo.command_bus');
        self::assertInstanceOf(MessageBusInterface::class, $commandBus);

        self::assertSame(0, $this->connection()->getTransactionNestingLevel());
        $commandBus->dispatch(new Envelope(new DeleteOrder($order), [new ReceivedStamp('async')]));
    }
}
