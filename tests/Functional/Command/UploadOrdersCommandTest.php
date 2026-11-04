<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Command;

use Setono\SyliusShipmondoPlugin\Event\SalesOrderMappedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShopFixtures;

final class UploadOrdersCommandTest extends FunctionalTestCase
{
    private const COMMAND = 'setono:sylius-shipmondo:upload-orders';

    private const PICKUP_POINT = [
        'id' => '96285',
        'name' => 'Pakkeshop Netto Vestergade',
        'address' => 'Vestergade 20',
        'zipcode' => '8000',
        'city' => 'Aarhus C',
        'country' => 'DK',
        'address2' => null,
    ];

    /**
     * @test
     */
    public function it_uploads_the_paid_and_authorized_orders_and_links_them_to_their_sales_orders(): void
    {
        $this->fixtures->shippingMethod->setAllowedShipmentTemplates([664116, 664115]);
        $customer = $this->fixtures->createCustomer();

        $paid = $this->fixtures->createOrder($customer, pickupPoint: self::PICKUP_POINT);
        $authorized = $this->fixtures->createOrder($customer, paid: false, authorized: true);
        $awaitingPayment = $this->fixtures->createOrder($customer, paid: false);
        $alreadyUploaded = $this->fixtures->createOrder($customer);
        $this->fixtures->markAsUploaded($alreadyUploaded, 999);
        $cart = $this->fixtures->createCart($customer);

        $this->respondWithShipmentTemplates();
        $this->shipmondo
            ->respond('POST', 'sales_orders', 201, ['id' => 1001])
            ->respond('POST', 'sales_orders', 201, ['id' => 1002])
        ;

        $tester = $this->executeCommand(self::COMMAND);
        $tester->assertCommandIsSuccessful();
        self::assertSame('', $tester->getDisplay());

        $requests = $this->shipmondo->requests('POST', 'sales_orders');
        self::assertCount(2, $requests);

        $uploaded = [];
        foreach ($requests as $request) {
            $body = ShipmondoApiStub::jsonBody($request);
            $uploaded[] = $body['order_id'];
        }
        self::assertSame([$paid->getNumber(), $authorized->getNumber()], $uploaded);

        $paidRow = $this->orderRow(self::id($paid));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $paidRow['shipmondo_state']);
        self::assertSame(1001, $paidRow['shipmondo_id']);

        $authorizedRow = $this->orderRow(self::id($authorized));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $authorizedRow['shipmondo_state']);
        self::assertSame(1002, $authorizedRow['shipmondo_id']);

        foreach ([$awaitingPayment, $cart] as $notUploaded) {
            $row = $this->orderRow(self::id($notUploaded));
            self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $row['shipmondo_state']);
            self::assertNull($row['shipmondo_id']);
        }

        self::assertSame(999, $this->orderRow(self::id($alreadyUploaded))['shipmondo_id']);
    }

    /**
     * @test
     */
    public function it_maps_the_order_to_a_shipmondo_sales_order(): void
    {
        $this->fixtures->shippingMethod->setAllowedShipmentTemplates([664116, 664115]);
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer(), pickupPoint: self::PICKUP_POINT);

        $this->respondWithShipmentTemplates();
        $this->shipmondo->respond('POST', 'sales_orders', 201, ['id' => 1001]);

        $tester = $this->executeCommand(self::COMMAND);
        $tester->assertCommandIsSuccessful();
        self::assertSame('', $tester->getDisplay());

        $requests = $this->shipmondo->requests('POST', 'sales_orders');
        self::assertCount(1, $requests);
        $body = ShipmondoApiStub::jsonBody($requests[0]);

        self::assertSame($order->getNumber(), $body['order_id']);
        self::assertSame('Sylius', $body['source_name']);
        self::assertSame('Please leave the parcel at the door', $body['order_note']);
        // the template for DK → DK (664116); 664115 ships to Sweden
        self::assertSame(664116, $body['shipment_template_id']);

        self::assertIsArray($body['ship_to']);
        self::assertSame('Jane Doe', $body['ship_to']['name']);
        self::assertSame('Vestergade 1', $body['ship_to']['address1']);
        self::assertSame('8000', $body['ship_to']['zipcode']);
        self::assertSame('Aarhus', $body['ship_to']['city']);
        self::assertSame(ShopFixtures::COUNTRY_CODE, $body['ship_to']['country_code']);
        self::assertSame('customer@example.com', $body['ship_to']['email']);

        self::assertSame([
            'id' => '96285',
            'name' => 'Pakkeshop Netto Vestergade',
            'address1' => 'Vestergade 20',
            'zipcode' => '8000',
            'city' => 'Aarhus C',
            'country_code' => 'DK',
        ], $body['service_point']);

        self::assertIsArray($body['order_lines']);
        $lines = [];
        foreach ($body['order_lines'] as $line) {
            self::assertIsArray($line);
            self::assertIsString($line['line_type']);
            $lines[$line['line_type']] = $line;
        }
        self::assertSame(['shipping', 'item'], array_keys($lines));

        self::assertSame('T-shirt (Blue)', $lines['item']['item_name']);
        self::assertSame('T_SHIRT_BLUE', $lines['item']['item_sku']);
        self::assertSame(2, $lines['item']['quantity']);
        self::assertSame('25', $lines['item']['unit_price_excluding_vat']);
        self::assertSame(250, $lines['item']['unit_weight']);

        self::assertSame('GLS pickup point', $lines['shipping']['item_name']);
        self::assertSame(1, $lines['shipping']['quantity']);
        self::assertSame('5', $lines['shipping']['unit_price_excluding_vat']);

        self::assertIsArray($body['payment_details']);
        self::assertSame('55', $body['payment_details']['amount_including_vat']);
        self::assertSame(ShopFixtures::CURRENCY_CODE, $body['payment_details']['currency_code']);
        self::assertSame('Bank transfer', $body['payment_details']['payment_method']);
        self::assertSame((string) ShopFixtures::PAYMENT_METHOD_SHIPMONDO_ID, $body['payment_details']['payment_gateway_id']);
    }

    /**
     * @test
     */
    public function it_does_nothing_when_no_order_is_ready_for_upload(): void
    {
        $this->fixtures->createOrder($this->fixtures->createCustomer(), paid: false);

        $tester = $this->executeCommand(self::COMMAND);
        $tester->assertCommandIsSuccessful();
        self::assertSame('', $tester->getDisplay());
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * @test
     *
     * @dataProvider failuresTheUploadRunCarriesOnAfter
     */
    public function it_settles_an_order_whose_upload_failed_and_keeps_uploading_the_other_orders(string $failure, string $state): void
    {
        $customer = $this->fixtures->createCustomer();
        $failed = $this->fixtures->createOrder($customer);
        $uploaded = $this->fixtures->createOrder($customer);

        $this->shipmondo->respond('GET', 'shipment_templates', 200, []);
        if ('mapping error' === $failure) {
            $this->failToMap($failed);
        } elseif ('network error' === $failure) {
            $this->shipmondo->failToConnect('POST', 'sales_orders');
        } else {
            $this->shipmondo->respond('POST', 'sales_orders', 'rejected' === $failure ? 422 : 500, ['error' => 'Something went wrong']);
        }
        $this->shipmondo->respond('POST', 'sales_orders', 201, ['id' => 1002]);

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        $failedRow = $this->orderRow(self::id($failed));
        self::assertSame([$state, null], [$failedRow['shipmondo_state'], $failedRow['shipmondo_id']]);

        $uploadedRow = $this->orderRow(self::id($uploaded));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1002], [$uploadedRow['shipmondo_state'], $uploadedRow['shipmondo_id']]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function failuresTheUploadRunCarriesOnAfter(): iterable
    {
        yield 'rejected upload (422)' => ['rejected', OrderInterface::SHIPMONDO_STATE_FAILED];
        yield 'temporary failure (500)' => ['server error', OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'temporary failure (network)' => ['network error', OrderInterface::SHIPMONDO_STATE_PENDING];
        yield 'the order cannot be turned into a sales order' => ['mapping error', OrderInterface::SHIPMONDO_STATE_FAILED];
    }

    /**
     * @test
     *
     * @dataProvider answersThatStopTheUploadRun
     */
    public function it_stops_the_upload_run_when_shipmondo_turns_the_store_away(int $statusCode): void
    {
        $customer = $this->fixtures->createCustomer();
        $turnedAway = $this->fixtures->createOrder($customer);
        $notTried = $this->fixtures->createOrder($customer);

        $this->shipmondo
            ->respond('GET', 'shipment_templates', 200, [])
            ->respond('POST', 'sales_orders', $statusCode, ['error' => 'No'])
        ;

        // fails, so whatever runs the command (cron, a scheduler) notices that no order can be uploaded
        $tester = $this->executeCommand(self::COMMAND);
        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The remaining orders stay pending for the next run.', (string) preg_replace('/\s+/', ' ', $tester->getDisplay()));

        self::assertCount(1, $this->shipmondo->requests('POST', 'sales_orders'));
        foreach ([$turnedAway, $notTried] as $order) {
            $row = $this->orderRow(self::id($order));
            self::assertSame([OrderInterface::SHIPMONDO_STATE_PENDING, null], [$row['shipmondo_state'], $row['shipmondo_id']]);
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function answersThatStopTheUploadRun(): iterable
    {
        yield 'credentials failure (401)' => [401];
        yield 'credentials failure (403)' => [403];
        yield 'rate limited (429)' => [429];
    }

    /**
     * @test
     */
    public function it_stops_the_upload_run_after_three_rejected_uploads_in_a_row(): void
    {
        $customer = $this->fixtures->createCustomer();
        $rejected = [$this->fixtures->createOrder($customer), $this->fixtures->createOrder($customer), $this->fixtures->createOrder($customer)];
        $notTried = $this->fixtures->createOrder($customer);

        // e.g. the payment gateway every order is mapped to was deleted in Shipmondo
        $this->shipmondo
            ->respond('GET', 'shipment_templates', 200, [])
            ->respond('POST', 'sales_orders', 422, ['error' => 'The payment gateway does not exist'])
        ;

        self::assertSame(Command::FAILURE, $this->executeCommand(self::COMMAND)->getStatusCode());

        self::assertCount(3, $this->shipmondo->requests('POST', 'sales_orders'));
        foreach ($rejected as $order) {
            self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($order))['shipmondo_state']);
        }
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->orderRow(self::id($notTried))['shipmondo_state']);
    }

    /**
     * @test
     */
    public function it_uploads_an_order_that_changed_after_the_upload_run_loaded_it(): void
    {
        $customer = $this->fixtures->createCustomer();
        $first = $this->fixtures->createOrder($customer);
        $changed = $this->fixtures->createOrder($customer);
        $last = $this->fixtures->createOrder($customer);

        // While the first order is uploaded, something else (another request, another run) changes the second order,
        // which the upload run has already loaded
        $changedOnce = false;
        $this->eventDispatcher()->addListener(
            'workflow.setono_sylius_shipmondo__order.completed.complete_upload',
            function () use (&$changedOnce, $changed): void {
                if ($changedOnce) {
                    return;
                }
                $changedOnce = true;

                $this->connection()->executeStatement('UPDATE sylius_order SET version = version + 1 WHERE id = ?', [self::id($changed)]);
            },
        );

        $this->shipmondo
            ->respond('GET', 'shipment_templates', 200, [])
            ->respond('POST', 'sales_orders', 201, ['id' => 1001])
            ->respond('POST', 'sales_orders', 201, ['id' => 1002])
            ->respond('POST', 'sales_orders', 201, ['id' => 1003])
        ;

        $this->executeCommand(self::COMMAND)->assertCommandIsSuccessful();

        self::assertTrue($changedOnce);
        self::assertSame(1001, $this->orderRow(self::id($first))['shipmondo_id']);
        self::assertSame(1002, $this->orderRow(self::id($changed))['shipmondo_id']);
        self::assertSame(1003, $this->orderRow(self::id($last))['shipmondo_id']);
    }

    /**
     * Makes turning the order into a sales order fail, as a data mapper that throws on inconsistent order data does
     */
    private function failToMap(OrderInterface $order): void
    {
        $orderId = self::id($order);

        $this->eventDispatcher()->addListener(SalesOrderMappedEvent::class, static function (SalesOrderMappedEvent $event) use ($orderId): void {
            if ($orderId === $event->order->getId()) {
                throw new \RuntimeException('The order has no shipping address');
            }
        });
    }

    private function eventDispatcher(): EventDispatcherInterface
    {
        $eventDispatcher = static::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);

        return $eventDispatcher;
    }

    private function respondWithShipmentTemplates(): void
    {
        $template = static fn (int $id, string $name, string $receiverCountry): array => [
            'id' => $id,
            'name' => $name,
            'sender' => ['country_code' => 'DK'],
            'receiver' => ['country_code' => $receiverCountry],
            'parcels' => [['quantity' => 1, 'weight' => 1000]],
        ];

        $this->shipmondo->respond('GET', 'shipment_templates', 200, [
            $template(664116, 'GLS pickup point (DK)', 'DK'),
            $template(664115, 'GLS pickup point (SE)', 'SE'),
            $template(664114, 'PostNord (DK)', 'DK'),
        ], ['X-Total-Pages' => '1']);
    }
}
