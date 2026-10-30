<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Order;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Order\OrderTransitions;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Webmozart\Assert\Assert;

/**
 * Cancelling an order that was uploaded to Shipmondo deletes its Shipmondo sales order.
 *
 * The admin tests submit the cancel button on the admin order page, i.e. Sylius's `sylius_admin_order_cancel`
 * route: ResourceController::applyStateMachineTransitionAction → Sylius's ResourceUpdateHandler, which opens a
 * database transaction around the `cancel` transition, inside which the plugin dispatches DeleteOrder on its
 * command bus (whose doctrine_transaction middleware nests another transaction).
 *
 * These tests are about whether that cancellation is really committed, under the nested-transaction semantics
 * applications using the plugin have (DBAL 3 without savepoints, where a rolled-back inner transaction marks the
 * outer one rollback-only). So they run outside DAMA's rolled-back transaction, without savepoints, and read the
 * result through a separate connection.
 */
final class OrderCancellationTest extends FunctionalTestCase
{
    private const SHIPMONDO_ID = 123456;

    protected static function commitsForReal(): bool
    {
        return true;
    }

    /**
     * @test
     */
    public function it_runs_with_committing_transactions_nested_without_savepoints(): void
    {
        $connection = $this->connection();
        self::assertFalse($connection->getNestTransactionsWithSavepoints());

        // a write committed on the application's connection is visible to a separate connection
        $connection->executeStatement('UPDATE sylius_channel SET name = ? WHERE code = ?', ['Committed', 'WEB']);
        self::assertSame('Committed', $this->database()->fetchOne('SELECT name FROM sylius_channel WHERE code = ?', ['WEB']));
    }

    /**
     * @test
     */
    public function it_deletes_the_sales_order_in_shipmondo_when_an_admin_cancels_an_uploaded_order(): void
    {
        $order = $this->uploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 204, null);

        $this->cancelInAdmin($order);

        self::assertResponseRedirects(sprintf('/admin/orders/%d', self::id($order)));
        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderInterface::STATE_CANCELLED, $row['state']);
        self::assertNull($row['shipmondo_id']);
        self::assertCount(1, $this->shipmondo->requests('DELETE', 'sales_orders/' . self::SHIPMONDO_ID));

        $this->browser->followRedirect();
        self::assertSelectorTextContains('.message.info', 'The order was deleted in Shipmondo because it was cancelled.');
    }

    /**
     * The scenario DeleteOrderHandler's "never throw" contract exists for: Shipmondo fails while the order is
     * being cancelled inside Sylius's own transaction. The cancellation must still be committed.
     *
     * @test
     */
    public function it_still_cancels_the_order_when_shipmondo_fails_to_delete_the_sales_order(): void
    {
        $order = $this->uploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 500, ['error' => 'Internal Server Error']);

        $this->cancelInAdmin($order);

        self::assertResponseRedirects(sprintf('/admin/orders/%d', self::id($order)));
        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderInterface::STATE_CANCELLED, $row['state']);
        // the sales order still exists in Shipmondo, so the order stays linked to it
        self::assertSame(self::SHIPMONDO_ID, $row['shipmondo_id']);
        self::assertCount(1, $this->shipmondo->requests('DELETE', 'sales_orders/' . self::SHIPMONDO_ID));

        $this->browser->followRedirect();
        self::assertSelectorTextContains('.message.negative', 'The order was cancelled, but it could not be deleted in Shipmondo.');
    }

    /**
     * @test
     */
    public function it_unlinks_the_order_when_the_sales_order_was_already_deleted_in_shipmondo(): void
    {
        $order = $this->uploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 404, ['error' => 'Not found']);

        $this->cancelInAdmin($order);

        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderInterface::STATE_CANCELLED, $row['state']);
        self::assertNull($row['shipmondo_id']);
    }

    /**
     * @test
     */
    public function it_does_not_call_shipmondo_when_an_order_that_was_never_uploaded_is_cancelled(): void
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());

        $this->cancelInAdmin($order);

        self::assertSame(OrderInterface::STATE_CANCELLED, $this->orderRow(self::id($order))['state']);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * Cancelling outside an HTTP request (e.g. from a console command or a message handler) has no session to
     * flash to, but still deletes the sales order.
     *
     * @test
     */
    public function it_deletes_the_sales_order_when_an_order_is_cancelled_outside_a_request(): void
    {
        $order = $this->uploadedOrder();
        $this->shipmondo->respond('DELETE', 'sales_orders/' . self::SHIPMONDO_ID, 204, null);

        $stateMachine = static::getContainer()->get('sylius_abstraction.state_machine');
        Assert::isInstanceOf($stateMachine, StateMachineInterface::class);
        $stateMachine->apply($order, OrderTransitions::GRAPH, OrderTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderInterface::STATE_CANCELLED, $row['state']);
        self::assertNull($row['shipmondo_id']);
        self::assertCount(1, $this->shipmondo->requests('DELETE', 'sales_orders/' . self::SHIPMONDO_ID));
    }

    private function uploadedOrder(): OrderInterface
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());
        $this->fixtures->markAsUploaded($order, self::SHIPMONDO_ID);

        return $order;
    }

    /**
     * Submits the "Cancel" button on the admin order page, exactly as an admin would
     */
    private function cancelInAdmin(OrderInterface $order): void
    {
        $this->loginAs($this->fixtures->admin(), 'admin');

        $crawler = $this->browser->request('GET', sprintf('/admin/orders/%d', self::id($order)));
        self::assertResponseIsSuccessful();

        $this->browser->submit($crawler->filter('form[action*="/cancel"]')->form());
    }
}
