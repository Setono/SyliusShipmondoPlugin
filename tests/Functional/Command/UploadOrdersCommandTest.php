<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Command;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
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
     */
    public function it_keeps_uploading_the_other_orders_when_shipmondo_rejects_one(): void
    {
        self::skipUntilFixed(17);

        $customer = $this->fixtures->createCustomer();
        $rejected = $this->fixtures->createOrder($customer);
        $accepted = $this->fixtures->createOrder($customer);

        $this->shipmondo
            ->respond('GET', 'shipment_templates', 200, [])
            ->respond('POST', 'sales_orders', 500, ['error' => 'Internal Server Error'])
            ->respond('POST', 'sales_orders', 201, ['id' => 1002])
        ;

        try {
            $this->executeCommand(self::COMMAND);
        } catch (\Throwable) {
            // whether the command reports the failure is up to the fix; what it leaves behind is what matters
        }

        // the other order is still uploaded in the same run
        self::assertCount(2, $this->shipmondo->requests('POST', 'sales_orders'));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $this->orderRow(self::id($accepted))['shipmondo_state']);

        // and the rejected order is either retried by the next run (pending) or visibly failed, not stuck in "uploading"
        self::assertContains($this->orderRow(self::id($rejected))['shipmondo_state'], [
            OrderInterface::SHIPMONDO_STATE_PENDING,
            OrderInterface::SHIPMONDO_STATE_FAILED,
        ]);
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
