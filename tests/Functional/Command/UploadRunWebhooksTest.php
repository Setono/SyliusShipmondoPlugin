<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Command;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\Console\Command\Command;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Tests\Setono\SyliusShipmondoPlugin\Functional\Support\ShipmondoApiStub;
use Tests\Setono\SyliusShipmondoPlugin\Support\CommandErrors;

/**
 * The upload run's last step registers the webhooks again where an admin last registered them, so an upgrade that
 * changes the actions the webhook handlers handle takes effect without a manual step
 */
final class UploadRunWebhooksTest extends FunctionalTestCase
{
    private const COMMAND = 'setono:sylius-shipmondo:upload-orders';

    /**
     * @test
     */
    public function it_succeeds_silently_after_one_fetch_when_this_shops_webhooks_match(): void
    {
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        $this->shipmondo->respond('GET', 'webhooks', 200, $this->wantedWebhooks(self::WEBHOOK_ENDPOINT), ['X-Total-Pages' => '1']);

        $tester = $this->executeCommand(self::COMMAND);

        $tester->assertCommandIsSuccessful();
        self::assertSame('', $tester->getDisplay());
        self::assertSame(['GET webhooks'], $this->shipmondo->requestLines());
        $registrations = $this->webhookRegistrations();
        self::assertCount(1, $registrations);
        self::assertSame(self::WEBHOOK_ENDPOINT, $registrations[0]['endpoint']);
        // registering again records when
        self::assertGreaterThan('2026-01-02 03:04:05', $registrations[0]['registeredAt']);
    }

    /**
     * E.g. after an upgrade of the plugin that handles a new action and no longer handles another
     *
     * @test
     */
    public function it_fixes_the_difference_at_the_recorded_endpoint_and_succeeds(): void
    {
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        $wanted = $this->wantedWebhooks(self::WEBHOOK_ENDPOINT);
        $missing = $wanted[0];
        $noLongerHandled = ['id' => 8001, 'name' => str_replace($missing['action'], 'create', $missing['name']), 'action' => 'create'] + $missing;

        $this->shipmondo
            ->respond('GET', 'webhooks', 200, [...array_slice($wanted, 1), $noLongerHandled], ['X-Total-Pages' => '1'])
            ->respond('POST', 'webhooks', 201, $missing)
            ->respond('DELETE', 'webhooks/8001', 204, null)
        ;

        $tester = $this->executeCommand(self::COMMAND);

        $tester->assertCommandIsSuccessful();
        self::assertSame('', $tester->getDisplay());
        self::assertSame(['GET webhooks', 'POST webhooks', 'DELETE webhooks/8001'], $this->shipmondo->requestLines());
        $created = ShipmondoApiStub::jsonBody($this->shipmondo->requests('POST', 'webhooks')[0]);
        self::assertSame([$missing['name'], self::WEBHOOK_ENDPOINT, $missing['action']], [$created['name'], $created['endpoint'], $created['action']]);
        self::assertSame([self::WEBHOOK_ENDPOINT], array_column($this->webhookRegistrations(), 'endpoint'));
        // Monolog puts the context in the message
        self::assertContains('This shop\'s webhooks in Shipmondo did not match the wanted ones at https://shop.example.com/webhook/shipmondo, so the missing ones were created and the unwanted ones deleted', array_column($this->logs(), 'message'));
    }

    /**
     * Never registered, or registered before the plugin recorded where: an admin registers them once, after which the
     * upload run keeps them current
     *
     * @test
     *
     * @dataProvider registeredWithoutAnEndpoint
     */
    public function it_uploads_the_pending_orders_registers_nothing_and_fails_without_a_recorded_endpoint(bool $registeredBefore): void
    {
        if ($registeredBefore) {
            $this->recordWebhookRegistration(null);
        }
        $order = $this->orderReadyForUpload();

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['The upload run did not register the webhooks again, because they are not registered yet: no registration with an endpoint is recorded. Register them once from the admin\'s Shipmondo page or with setono:sylius-shipmondo:register-webhooks, after which the upload run keeps them current. To keep them yourself, turn off setono_sylius_shipmondo.webhooks.register_automatically. The pending orders were uploaded.'],
            CommandErrors::of($tester),
        );
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $this->orderRow(self::id($order))['shipmondo_state']);
        // nothing was asked of Shipmondo about the webhooks
        self::assertSame([], $this->shipmondo->requests('GET', 'webhooks'));
        self::assertSame($registeredBefore ? [null] : [], array_column($this->webhookRegistrations(), 'endpoint'));
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function registeredWithoutAnEndpoint(): iterable
    {
        yield 'never registered' => [false];
        yield 'registered before the endpoint was recorded' => [true];
    }

    /**
     * @test
     */
    public function it_logs_a_shipmondo_failure_and_fails_after_uploading_the_pending_orders(): void
    {
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        $order = $this->orderReadyForUpload();
        $this->shipmondo->respond('GET', 'webhooks', 503, ['error' => 'Service unavailable']);

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['The upload run could not compare the webhooks with Shipmondo\'s, or register them again, because a call to Shipmondo failed. The log has why. The next run tries again, and the pending orders were uploaded.'],
            CommandErrors::of($tester),
        );
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $this->orderRow(self::id($order))['shipmondo_state']);
        self::assertSame(['2026-01-02 03:04:05'], array_column($this->webhookRegistrations(), 'registeredAt'));

        $errors = array_values(array_filter($this->logs(), static fn (array $log): bool => 'error' === $log['level']));
        self::assertCount(1, $errors);
        self::assertSame('The upload run could not register the webhooks again, because a call to Shipmondo failed. The next upload run tries again', $errors[0]['message']);
        self::assertSame(['temporary_failure', 503], [$errors[0]['context']['shipmondoFailure'], $errors[0]['context']['shipmondoStatusCode']]);
    }

    /**
     * Shipmondo lists every webhook on the account, also other integrations', which may have an action or a resource the
     * Shipmondo SDK doesn't know, e.g. one Shipmondo added later. The SDK reads it as null, so the list can still be
     * compared, and another integration's webhook is left alone
     *
     * @test
     */
    public function it_succeeds_when_another_integration_has_a_webhook_whose_action_the_sdk_does_not_know(): void
    {
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        $order = $this->orderReadyForUpload();
        $this->shipmondo->respond('GET', 'webhooks', 200, [
            ...$this->wantedWebhooks(self::WEBHOOK_ENDPOINT),
            ['id' => 8001, 'endpoint' => 'https://another-integration.example.com/hook', 'active' => true, 'name' => 'Another integration', 'action' => 'an_action_added_later', 'resource_name' => 'A resource added later'],
        ], ['X-Total-Pages' => '1']);

        $tester = $this->executeCommand(self::COMMAND);

        $tester->assertCommandIsSuccessful();
        self::assertSame('', $tester->getDisplay());
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $this->orderRow(self::id($order))['shipmondo_state']);
        self::assertSame(['GET payment_gateways', 'POST sales_orders', 'GET webhooks'], $this->shipmondo->requestLines());
    }

    /**
     * @test
     */
    public function it_logs_an_unreachable_recorded_endpoint_and_fails_after_uploading_the_pending_orders(): void
    {
        $this->recordWebhookRegistration('http://localhost/webhook/shipmondo');
        $order = $this->orderReadyForUpload();

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['The upload run did not register the webhooks again, because Shipmondo can\'t reach the endpoint they were last registered at, which the log names. Register them from the admin\'s Shipmondo page, opened at the shop\'s hostname, or with setono:sylius-shipmondo:register-webhooks. The pending orders were uploaded.'],
            CommandErrors::of($tester),
        );
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, $this->orderRow(self::id($order))['shipmondo_state']);
        self::assertSame([], $this->shipmondo->requests('GET', 'webhooks'));
        self::assertContains(
            ['level' => 'error', 'message' => 'The upload run did not register the webhooks again, because Shipmondo can\'t reach http://localhost/webhook/shipmondo, where they were last registered. Register them from the admin\'s Shipmondo page, opened at the shop\'s hostname, or with setono:sylius-shipmondo:register-webhooks', 'context' => ['endpoint' => 'http://localhost/webhook/shipmondo']],
            $this->logs(),
        );
    }

    /**
     * The remaining orders wait for the next run, and the webhooks would most likely fail the same way
     *
     * @test
     */
    public function it_does_nothing_about_the_webhooks_when_the_upload_run_stopped_early(): void
    {
        $this->recordWebhookRegistration(self::WEBHOOK_ENDPOINT);
        $this->orderReadyForUpload(created: false);
        $this->shipmondo->respond('POST', 'sales_orders', 401, ['error' => 'Invalid credentials']);

        $tester = $this->executeCommand(self::COMMAND);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame(
            ['The upload run stopped, because Shipmondo did not accept the API credentials. Check the Shipmondo API user and key. The remaining orders wait for the next run.'],
            CommandErrors::of($tester),
        );
        // an unstubbed request for the webhooks would fail the test too
        self::assertSame([], $this->shipmondo->requests('GET', 'webhooks'));
    }

    /**
     * A paid order that qualifies for upload, and Shipmondo's answers to its upload
     *
     * @param bool $created whether Shipmondo creates its sales order, or the test answers the upload itself
     */
    private function orderReadyForUpload(bool $created = true): OrderInterface
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer());

        $this->respondWithThePaymentGateway();
        if ($created) {
            $this->shipmondo->respond('POST', 'sales_orders', 201, ['id' => 1001]);
        }

        return $order;
    }
}
