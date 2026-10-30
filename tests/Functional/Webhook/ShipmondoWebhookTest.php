<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Webhook;

use Firebase\JWT\JWT;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Core\OrderShippingStates;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Webhook\WebhookPayloadFixtures;
use Webmozart\Assert\Assert;

/**
 * Posts signed Shipmondo webhooks (real captured payloads) to the Symfony webhook endpoint and asserts what the
 * plugin's remote event handlers persisted.
 */
final class ShipmondoWebhookTest extends FunctionalTestCase
{
    private const SHIPMONDO_ID = 37707019;

    /**
     * @test
     */
    public function it_ships_and_fulfils_a_paid_order_when_shipmondo_reports_it_fully_shipped(): void
    {
        $order = $this->uploadedOrder();

        $this->postWebhook('Orders', 'create_shipment', $this->payload('orders_create_shipment', $order));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('OK', $this->browser->getResponse()->getContent());

        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderShippingStates::STATE_SHIPPED, $row['shipping_state']);
        self::assertSame(OrderInterface::STATE_FULFILLED, $row['state']);
        self::assertSame(['shipped'], $this->shipmentStates($order));
    }

    /**
     * @test
     */
    public function it_finds_the_order_by_its_number_when_the_sales_order_id_is_not_linked(): void
    {
        $this->fixtures->createShop();
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());

        // e.g. a sales order created in Shipmondo by another integration: only order_id (the order number) matches
        $this->postWebhook('Orders', 'create_shipment', $this->payload('orders_create_shipment', $order));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrderShippingStates::STATE_SHIPPED, $this->orderRow(self::id($order))['shipping_state']);
    }

    /**
     * @test
     */
    public function it_ignores_a_status_update_for_an_order_that_is_packed_but_not_shipped(): void
    {
        $order = $this->uploadedOrder();

        $this->postWebhook('Orders', 'status_update', $this->payload('orders_status_update', $order));

        self::assertResponseStatusCodeSame(200);

        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderShippingStates::STATE_READY, $row['shipping_state']);
        self::assertSame(OrderInterface::STATE_NEW, $row['state']);
        self::assertSame(['ready'], $this->shipmentStates($order));
    }

    /**
     * @test
     */
    public function it_completes_an_authorized_payment_when_shipmondo_reports_it_captured(): void
    {
        $order = $this->uploadedOrder(authorized: true);
        self::assertSame(OrderPaymentStates::STATE_AUTHORIZED, $this->orderRow(self::id($order))['payment_state']);

        $this->postWebhook('Orders', 'payment_captured', $this->payload('orders_payment_captured', $order));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrderPaymentStates::STATE_PAID, $this->orderRow(self::id($order))['payment_state']);
        self::assertSame(['completed'], $this->paymentStates($order));
    }

    /**
     * @test
     */
    public function it_cancels_the_payment_state_when_shipmondo_reports_the_authorization_voided(): void
    {
        $order = $this->uploadedOrder(authorized: true);

        // no voided delivery was captured from the sandbox; the action header is what tells the events apart
        $this->postWebhook('Orders', 'payment_voided', $this->payload('orders_payment_captured', $order));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrderPaymentStates::STATE_CANCELLED, $this->orderRow(self::id($order))['payment_state']);
        // the payment itself is left alone, so Sylius does not spawn a replacement payment
        self::assertSame(['authorized'], $this->paymentStates($order));
    }

    /**
     * @test
     */
    public function it_resets_the_upload_state_when_the_sales_order_is_deleted_in_shipmondo(): void
    {
        $order = $this->uploadedOrder();

        $this->postWebhook('Orders', 'delete', $this->payload('orders_delete', $order));

        self::assertResponseStatusCodeSame(200);

        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $row['shipmondo_state']);
        self::assertNull($row['shipmondo_id']);
        // deleting the sales order in Shipmondo is not a cancellation
        self::assertSame(OrderInterface::STATE_NEW, $row['state']);
    }

    /**
     * @test
     */
    public function it_keeps_the_link_when_a_duplicate_sales_order_with_the_same_order_number_is_deleted(): void
    {
        $order = $this->uploadedOrder();

        $payload = $this->payload('orders_delete', $order);
        $payload['id'] = self::SHIPMONDO_ID + 1;

        $this->postWebhook('Orders', 'delete', $payload);

        self::assertResponseStatusCodeSame(200);

        $row = $this->orderRow(self::id($order));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $row['shipmondo_state']);
        self::assertSame(self::SHIPMONDO_ID, $row['shipmondo_id']);
    }

    /**
     * @test
     */
    public function it_acknowledges_a_webhook_for_an_order_it_does_not_know(): void
    {
        $order = $this->uploadedOrder();

        $payload = WebhookPayloadFixtures::load('orders_create_shipment');
        $payload['id'] = 1;
        $payload['order_id'] = 'UNKNOWN';

        $this->postWebhook('Orders', 'create_shipment', $payload);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(OrderShippingStates::STATE_READY, $this->orderRow(self::id($order))['shipping_state']);
    }

    /**
     * @test
     */
    public function it_rejects_a_webhook_signed_with_another_key_and_changes_nothing(): void
    {
        $order = $this->uploadedOrder();

        $this->postWebhook('Orders', 'create_shipment', $this->payload('orders_create_shipment', $order), 'another-key-that-is-also-at-least-32-bytes');

        self::assertResponseStatusCodeSame(406);
        self::assertSame(OrderShippingStates::STATE_READY, $this->orderRow(self::id($order))['shipping_state']);
    }

    /**
     * @test
     */
    public function it_rejects_a_request_without_the_shipmondo_headers(): void
    {
        $order = $this->uploadedOrder();

        $this->browser->request(
            'POST',
            '/webhook/shipmondo',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $this->signedBody($this->payload('orders_create_shipment', $order), $this->webhooksKey()),
        );

        self::assertResponseStatusCodeSame(406);
        self::assertSame(OrderShippingStates::STATE_READY, $this->orderRow(self::id($order))['shipping_state']);
    }

    private function uploadedOrder(bool $authorized = false): OrderInterface
    {
        $this->fixtures->createShop();
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer(), paid: !$authorized, authorized: $authorized);
        $this->fixtures->markAsUploaded($order, self::SHIPMONDO_ID);

        return $order;
    }

    /**
     * A captured payload, pointed at the given order
     *
     * @return array<string, mixed>
     */
    private function payload(string $fixture, OrderInterface $order): array
    {
        $payload = WebhookPayloadFixtures::load($fixture);
        $payload['id'] = self::SHIPMONDO_ID;
        $payload['order_id'] = $order->getNumber();

        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function postWebhook(string $resourceType, string $action, array $data, ?string $key = null): void
    {
        $this->browser->request(
            'POST',
            '/webhook/shipmondo',
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_SMD_RESOURCE_TYPE' => $resourceType,
                'HTTP_SMD_ACTION' => $action,
            ],
            content: $this->signedBody($data, $key ?? $this->webhooksKey()),
        );
    }

    /**
     * The body Shipmondo posts: the HS256-signed envelope, carrying the resource in its `data` claim
     *
     * @param array<string, mixed> $data
     */
    private function signedBody(array $data, string $key): string
    {
        $jwt = JWT::encode([
            'webhook' => 'Setono Sylius Shipmondo Plugin - [orders:test]',
            'data' => $data,
            'url' => 'https://localhost/webhook/shipmondo',
        ], $key, 'HS256');

        return json_encode(['data' => $jwt], \JSON_THROW_ON_ERROR);
    }

    private function webhooksKey(): string
    {
        $key = static::getContainer()->getParameter('setono_sylius_shipmondo.webhooks.key');
        Assert::stringNotEmpty($key);

        return $key;
    }

    /**
     * @return list<string>
     */
    private function shipmentStates(OrderInterface $order): array
    {
        /** @var list<string> $states */
        $states = $this->observer()->fetchFirstColumn('SELECT state FROM sylius_shipment WHERE order_id = ?', [self::id($order)]);

        return $states;
    }

    /**
     * @return list<string>
     */
    private function paymentStates(OrderInterface $order): array
    {
        /** @var list<string> $states */
        $states = $this->observer()->fetchFirstColumn('SELECT state FROM sylius_payment WHERE order_id = ? ORDER BY id', [self::id($order)]);

        return $states;
    }
}
