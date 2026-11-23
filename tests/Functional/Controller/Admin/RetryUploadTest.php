<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Admin;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Workflow\OrderWorkflow;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Form;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;

/**
 * An admin retries the upload of a failed order, from the order's page or for the orders selected in the order grid,
 * which the admin can filter on the Shipmondo state. A retried order is pending again, so the next upload run uploads it
 */
final class RetryUploadTest extends FunctionalTestCase
{
    private const RETRIED = 'The order is pending again, so the next upload run will upload it to Shipmondo.';

    private const NOT_RETRIED = 'The upload of the order was not retried, because its Shipmondo state is no longer failed.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->loginAs($this->fixtures->admin(), 'admin');
    }

    /**
     * @test
     *
     * @dataProvider shipmondoStates
     */
    public function it_shows_the_shipmondo_state_of_the_order_on_its_page(string $state, ?int $salesOrderId, string $label, string $class): void
    {
        $order = $this->createOrder($state, $salesOrderId);

        $section = $this->shipmondoSection($order);

        $stateLabel = $section->filter('.ui.label');
        self::assertSame($label, trim($stateLabel->text()));
        self::assertSame($class, self::classes($stateLabel));
        self::assertStringContainsString('Shipmondo', $section->filter('.header')->first()->text());

        if (null === $salesOrderId) {
            self::assertStringNotContainsString('Sales order id', $section->text());
        } else {
            self::assertStringContainsString(sprintf('Sales order id %d', $salesOrderId), self::normalized($section->text()));
        }

        self::assertCount(OrderInterface::SHIPMONDO_STATE_FAILED === $state ? 1 : 0, $section->filter('button:contains("Retry upload")'));
    }

    /**
     * @return iterable<string, array{string, int|null, string, string}>
     */
    public static function shipmondoStates(): iterable
    {
        yield 'pending' => [OrderInterface::SHIPMONDO_STATE_PENDING, null, 'Pending', 'ui top attached label'];
        yield 'uploading' => [OrderInterface::SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO, null, 'Uploading to Shipmondo', 'ui olive top attached label'];
        yield 'uploaded' => [OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001, 'Uploaded to Shipmondo', 'ui green top attached label'];
        yield 'unlinked' => [OrderInterface::SHIPMONDO_STATE_UNLINKED, null, 'Unlinked', 'ui orange top attached label'];
        yield 'failed' => [OrderInterface::SHIPMONDO_STATE_FAILED, null, 'Failed', 'ui red top attached label'];
        yield 'cancelled' => [OrderInterface::SHIPMONDO_STATE_CANCELLED, null, 'Cancelled', 'ui yellow top attached label'];
    }

    /**
     * @test
     */
    public function it_retries_the_upload_of_a_failed_order_from_its_page_so_the_next_upload_run_uploads_it(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);

        $this->browser->submit($this->retryForm($order));

        self::assertResponseRedirects(sprintf('/admin/orders/%d', self::id($order)));
        $crawler = $this->browser->followRedirect();
        self::assertSame([self::RETRIED], self::flashes($crawler, 'positive'));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->orderRow(self::id($order))['shipmondo_state']);
        // nothing was asked of Shipmondo
        self::assertSame([], $this->shipmondo->requests());

        $this->respondWithThePaymentGateway();
        $this->shipmondo->respond('POST', 'sales_orders', 201, ['id' => 1001]);

        $this->executeCommand('setono:sylius-shipmondo:upload-orders')->assertCommandIsSuccessful();

        self::assertCount(1, $this->shipmondo->requests('POST', 'sales_orders'));
        $row = $this->orderRow(self::id($order));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$row['shipmondo_state'], $row['shipmondo_id']]);
    }

    /**
     * The order was failed when the admin opened its page, but was cancelled in another request before the admin
     * clicked Retry upload
     *
     * @test
     */
    public function it_leaves_an_order_that_is_no_longer_failed_as_it_is_and_tells_the_admin(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);
        $form = $this->retryForm($order);
        $this->connection()->executeStatement(
            'UPDATE sylius_order SET state = ?, shipmondoState = ?, version = version + 1 WHERE id = ?',
            [OrderInterface::STATE_CANCELLED, OrderInterface::SHIPMONDO_STATE_CANCELLED, self::id($order)],
        );

        $this->browser->submit($form);

        self::assertResponseRedirects(sprintf('/admin/orders/%d', self::id($order)));
        $crawler = $this->browser->followRedirect();
        self::assertSame([self::NOT_RETRIED], self::flashes($crawler, 'negative'));
        self::assertSame([], self::flashes($crawler, 'positive'));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_CANCELLED, $this->orderRow(self::id($order))['shipmondo_state']);
    }

    /**
     * @test
     */
    public function it_refuses_to_retry_the_upload_of_an_order_without_a_valid_csrf_token(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);
        $form = $this->retryForm($order);
        $form['_csrf_token'] = 'forged';

        $this->browser->submit($form);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($order))['shipmondo_state']);
    }

    /**
     * @test
     */
    public function it_refuses_to_retry_the_upload_of_an_order_on_a_get(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);
        $token = self::fieldValue($this->retryForm($order), '_csrf_token');

        $this->browser->request('GET', sprintf('/admin/orders/%d/retry-upload', self::id($order)), ['_csrf_token' => $token]);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($order))['shipmondo_state']);
    }

    /**
     * @test
     */
    public function it_filters_the_order_grid_on_the_shipmondo_state(): void
    {
        $failed = [$this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED), $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED)];
        $this->createOrder();
        $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001);
        $this->createOrder(OrderInterface::SHIPMONDO_STATE_CANCELLED);

        $crawler = $this->browser->request('GET', '/admin/orders/');
        self::assertResponseIsSuccessful();
        $filter = $crawler->filter('form')->reduce(static fn (Crawler $form): bool => 1 === $form->filter('select[name="criteria[shipmondo_state]"]')->count())->form();
        self::assertSame(
            ['', 'pending', 'uploading_to_shipmondo', 'uploaded_to_shipmondo', 'unlinked', 'failed', 'cancelled'],
            self::choice($filter, 'criteria[shipmondo_state]')->availableOptionValues(),
        );
        self::assertSame(
            ['All', 'Pending', 'Uploading to Shipmondo', 'Uploaded to Shipmondo', 'Unlinked', 'Failed', 'Cancelled'],
            $crawler->filter('select[name="criteria[shipmondo_state]"] option')->each(static fn (Crawler $option): string => trim($option->text())),
        );

        self::choice($filter, 'criteria[shipmondo_state]')->select('failed');
        $crawler = $this->browser->submit($filter);

        self::assertResponseIsSuccessful();
        self::assertSame(self::sorted(array_map(static fn (OrderInterface $order): string => '#' . (string) $order->getNumber(), $failed)), self::sorted(self::listedOrderNumbers($crawler)));
    }

    /**
     * @test
     */
    public function it_retries_the_upload_of_the_selected_failed_orders_and_counts_the_orders_it_left_as_they_were(): void
    {
        $failed = [$this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED), $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED)];
        $pending = $this->createOrder();
        $uploaded = $this->createOrder(OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001);
        $notSelected = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);

        $this->bulkRetry([...$failed, $pending, $uploaded]);

        self::assertResponseRedirects('/admin/orders/');
        $crawler = $this->browser->followRedirect();
        self::assertSame(['2 orders are pending again, so the next upload run will upload them to Shipmondo.'], self::flashes($crawler, 'positive'));
        self::assertSame(['2 orders were left as they were, because their Shipmondo state is not failed.'], self::flashes($crawler, 'info'));

        foreach ($failed as $order) {
            self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->orderRow(self::id($order))['shipmondo_state']);
        }
        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->orderRow(self::id($pending))['shipmondo_state']);
        $row = $this->orderRow(self::id($uploaded));
        self::assertSame([OrderInterface::SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO, 1001], [$row['shipmondo_state'], $row['shipmondo_id']]);
        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($notSelected))['shipmondo_state']);
        self::assertSame([], $this->shipmondo->requests());
    }

    /**
     * @test
     */
    public function it_counts_one_retried_order_and_one_order_it_left_as_it_was(): void
    {
        $this->bulkRetry([$this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED), $this->createOrder(OrderInterface::SHIPMONDO_STATE_CANCELLED)]);

        $crawler = $this->browser->followRedirect();
        self::assertSame(['1 order is pending again, so the next upload run will upload it to Shipmondo.'], self::flashes($crawler, 'positive'));
        self::assertSame(['1 order was left as it was, because its Shipmondo state is not failed.'], self::flashes($crawler, 'info'));
    }

    /**
     * @test
     */
    public function it_says_so_when_none_of_the_selected_orders_was_retried(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_UNLINKED);

        $this->bulkRetry([$order]);

        $crawler = $this->browser->followRedirect();
        self::assertSame([], self::flashes($crawler, 'positive'));
        self::assertSame([
            'No order was retried.',
            '1 order was left as it was, because its Shipmondo state is not failed.',
        ], self::flashes($crawler, 'info'));
        self::assertSame(OrderInterface::SHIPMONDO_STATE_UNLINKED, $this->orderRow(self::id($order))['shipmondo_state']);
    }

    /**
     * @test
     */
    public function it_refuses_to_retry_the_upload_of_the_selected_orders_without_a_valid_csrf_token(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);

        $this->bulkRetry([$order], 'forged');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($order))['shipmondo_state']);
    }

    /**
     * @test
     */
    public function it_refuses_to_retry_the_upload_of_the_selected_orders_on_a_get(): void
    {
        $order = $this->createOrder(OrderInterface::SHIPMONDO_STATE_FAILED);
        $form = $this->bulkRetryForm();

        $this->browser->request('GET', $form->getUri(), ['_csrf_token' => self::fieldValue($form, '_csrf_token'), 'ids' => [(string) self::id($order)]]);

        self::assertResponseStatusCodeSame(405);
        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($order))['shipmondo_state']);
    }

    private function createOrder(string $state = OrderInterface::SHIPMONDO_STATE_PENDING, ?int $salesOrderId = null): OrderInterface
    {
        $order = $this->fixtures->createOrder($this->fixtures->createCustomer(sprintf('customer-%s@example.com', bin2hex(random_bytes(4)))));
        $order->setShipmondoState($state, [OrderWorkflow::CONTEXT_SALES_ORDER_ID => $salesOrderId]);
        $this->entityManager()->flush();

        return $order;
    }

    private function shipmondoSection(OrderInterface $order): Crawler
    {
        $crawler = $this->browser->request('GET', sprintf('/admin/orders/%d', self::id($order)));
        self::assertResponseIsSuccessful();

        $section = $crawler->filter('#setono-sylius-shipmondo-order');
        self::assertCount(1, $section, 'The order page has a Shipmondo section');

        return $section;
    }

    private function retryForm(OrderInterface $order): Form
    {
        $button = $this->shipmondoSection($order)->selectButton('Retry upload');
        self::assertCount(1, $button);

        return $button->form();
    }

    private function bulkRetryForm(): Form
    {
        $crawler = $this->browser->request('GET', '/admin/orders/');
        self::assertResponseIsSuccessful();

        $button = $crawler->filter('.sylius-grid-nav__bulk')->selectButton('Retry upload to Shipmondo');
        self::assertCount(1, $button);
        // the admin's scripts add the selected orders to the form once the admin confirms
        self::assertNotNull($button->attr('data-bulk-action-requires-confirmation'));

        return $button->form();
    }

    /**
     * Submits the bulk action's form as the admin's scripts do: with the ids of the orders selected in the grid
     *
     * @param list<OrderInterface> $orders
     */
    private function bulkRetry(array $orders, ?string $csrfToken = null): void
    {
        $form = $this->bulkRetryForm();
        $values = $form->getPhpValues();
        if (null !== $csrfToken) {
            $values['_csrf_token'] = $csrfToken;
        }
        $values['ids'] = array_map(static fn (OrderInterface $order): string => (string) self::id($order), $orders);

        $this->browser->request($form->getMethod(), $form->getUri(), $values);
    }

    /**
     * The texts of the flash messages of a kind, by their Semantic UI class: positive (success), negative (error) or info
     *
     * @return list<string>
     */
    private static function flashes(Crawler $crawler, string $class): array
    {
        $flashes = [];
        foreach ($crawler->filter(sprintf('.sylius-flash-message.%s .content p', $class)) as $flash) {
            $flashes[] = self::normalized($flash->textContent);
        }

        return $flashes;
    }

    /**
     * @return list<string>
     */
    private static function listedOrderNumbers(Crawler $crawler): array
    {
        $numbers = [];
        foreach ($crawler->filter('table tbody tr a') as $link) {
            $text = trim($link->textContent);
            if (str_starts_with($text, '#')) {
                $numbers[] = $text;
            }
        }

        return $numbers;
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private static function fieldValue(Form $form, string $name): string
    {
        $field = $form->get($name);
        self::assertInstanceOf(FormField::class, $field);

        $value = $field->getValue();
        self::assertIsString($value);

        return $value;
    }

    private static function classes(Crawler $element): string
    {
        return implode(' ', array_filter(explode(' ', (string) $element->attr('class')), static fn (string $class): bool => '' !== $class));
    }

    private static function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
