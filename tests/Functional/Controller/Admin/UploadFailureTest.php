<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller\Admin;

use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Symfony\Component\DomCrawler\Crawler;
use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;
use Webmozart\Assert\Assert;

/**
 * The order page's Shipmondo section tells an admin why the order's latest upload didn't go through, and what happens
 * next, without reading the logs
 */
final class UploadFailureTest extends FunctionalTestCase
{
    private const NOTE_FAILED = 'Fix what made the upload fail, then retry the upload.';

    private const NOTE_UNLINKED = 'Nothing to retry: Shipmondo may have a sales order for the order, so the first upload run 10 minutes after this looks for it, links the order to it if it finds one, and uploads the order again only if Shipmondo has none.';

    private const NOTE_PENDING = 'The next upload run uploads the order again.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->respondWithThePaymentGateway();
    }

    /**
     * @test
     */
    public function it_shows_why_shipmondo_rejected_the_upload_of_a_failed_order_and_when(): void
    {
        $order = $this->createOrder();
        $this->shipmondo->respond('POST', 'sales_orders', 422, ['error' => 'The payment gateway does not exist']);

        $this->executeCommand('setono:sylius-shipmondo:upload-orders')->assertCommandIsSuccessful();

        self::assertSame(OrderInterface::SHIPMONDO_STATE_FAILED, $this->orderRow(self::id($order))['shipmondo_state']);
        $section = $this->shipmondoSection($order);
        self::assertSame(
            sprintf('Last upload Shipmondo refused the request: The payment gateway does not exist Status code 422 When %s %s', $this->stateChangedAt($order), self::NOTE_FAILED),
            self::failureText($section),
        );
        self::assertCount(1, $section->filter('.message.negative'));
        self::assertCount(1, $section->selectButton('Retry upload'));
    }

    /**
     * Shipmondo didn't answer, so there is no status code to show
     *
     * @test
     */
    public function it_shows_why_the_upload_of_a_failed_order_failed_unexpectedly(): void
    {
        $order = $this->createOrder();
        $this->failToMap($order);

        $this->executeCommand('setono:sylius-shipmondo:upload-orders')->assertCommandIsSuccessful();

        self::assertSame(
            sprintf('Last upload Something unexpected went wrong when calling Shipmondo: The order has no shipping address When %s %s', $this->stateChangedAt($order), self::NOTE_FAILED),
            self::failureText($this->shipmondoSection($order)),
        );
        self::assertSame([], $this->shipmondo->requests('POST', 'sales_orders'));
    }

    /**
     * Shipmondo may have created the sales order before the failure, so "Try again later" alone would mislead
     *
     * @test
     */
    public function it_says_a_sales_order_lookup_settles_an_order_its_upload_made_unlinked(): void
    {
        $order = $this->createOrder();
        $this->shipmondo->respond('POST', 'sales_orders', 500, ['error' => 'Internal Server Error']);

        $this->executeCommand('setono:sylius-shipmondo:upload-orders')->assertCommandIsSuccessful();

        self::assertSame(OrderInterface::SHIPMONDO_STATE_UNLINKED, $this->orderRow(self::id($order))['shipmondo_state']);
        $section = $this->shipmondoSection($order);
        self::assertSame(
            sprintf('Last upload Shipmondo could not be reached, or had a server error. Try again later. Status code 500 When %s %s', $this->stateChangedAt($order), self::NOTE_UNLINKED),
            self::failureText($section),
        );
        self::assertCount(1, $section->filter('.message.warning'));
        self::assertCount(0, $section->selectButton('Retry upload'));
    }

    /**
     * Shipmondo turned the store away, which says nothing about the order
     *
     * @test
     */
    public function it_says_the_next_upload_run_uploads_an_order_again_that_its_upload_put_back_to_pending(): void
    {
        $order = $this->createOrder();
        $this->shipmondo->respond('POST', 'sales_orders', 429, ['error' => 'Too Many Requests']);

        // the upload run stops, so cron monitoring notices
        self::assertSame(1, $this->executeCommand('setono:sylius-shipmondo:upload-orders')->getStatusCode());

        self::assertSame(OrderInterface::SHIPMONDO_STATE_PENDING, $this->orderRow(self::id($order))['shipmondo_state']);
        self::assertSame(
            sprintf('Last upload Shipmondo is limiting how often the store may call it. Try again in a minute. Status code 429 When %s %s', $this->stateChangedAt($order), self::NOTE_PENDING),
            self::failureText($this->shipmondoSection($order)),
        );
    }

    /**
     * @test
     */
    public function it_no_longer_shows_the_failure_once_an_admin_retries_the_upload(): void
    {
        $order = $this->createOrder();
        $this->shipmondo->respond('POST', 'sales_orders', 422, ['error' => 'The payment gateway does not exist']);
        $this->executeCommand('setono:sylius-shipmondo:upload-orders')->assertCommandIsSuccessful();

        $this->browser->submit($this->shipmondoSection($order)->selectButton('Retry upload')->form());

        self::assertResponseRedirects(sprintf('/admin/orders/%d', self::id($order)));
        $section = $this->browser->followRedirect()->filter('#setono-sylius-shipmondo-order');
        self::assertCount(1, $section);
        self::assertCount(0, $section->filter('#setono-sylius-shipmondo-upload-failure'));
    }

    /**
     * @test
     */
    public function it_shows_no_failure_for_an_order_whose_upload_went_through(): void
    {
        $order = $this->createOrder();
        $this->shipmondo->respond('POST', 'sales_orders', 201, ['id' => 1001]);

        $this->executeCommand('setono:sylius-shipmondo:upload-orders')->assertCommandIsSuccessful();

        $section = $this->shipmondoSection($order);
        self::assertCount(0, $section->filter('#setono-sylius-shipmondo-upload-failure'));
        self::assertStringContainsString('Sales order id 1001', self::normalized($section->text()));
    }

    private function createOrder(): OrderInterface
    {
        return $this->fixtures->createOrder($this->fixtures->createCustomer(sprintf('customer-%s@example.com', bin2hex(random_bytes(4)))));
    }

    private function shipmondoSection(OrderInterface $order): Crawler
    {
        $this->loginAs($this->fixtures->admin(), 'admin');

        $crawler = $this->browser->request('GET', sprintf('/admin/orders/%d', self::id($order)));
        self::assertResponseIsSuccessful();

        $section = $crawler->filter('#setono-sylius-shipmondo-order');
        self::assertCount(1, $section, 'The order page has a Shipmondo section');

        return $section;
    }

    /**
     * When the order's Shipmondo state last changed, as the database has it, the way the order page shows it
     */
    private function stateChangedAt(OrderInterface $order): string
    {
        $changedAt = $this->database()->fetchOne('SELECT shipmondoStateChangedAt FROM sylius_order WHERE id = ?', [self::id($order)]);
        Assert::string($changedAt);

        return (new \DateTimeImmutable($changedAt))->format('d-m-Y H:i:s');
    }

    private static function failureText(Crawler $section): string
    {
        $failure = $section->filter('#setono-sylius-shipmondo-upload-failure');
        self::assertCount(1, $failure, 'The Shipmondo section shows the failure');

        return self::normalized($failure->text());
    }

    private static function normalized(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
