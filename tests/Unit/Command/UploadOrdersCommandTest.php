<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Command\UploadOrdersCommand;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\UploadOutcome;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UploadOrdersCommandTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_uploads_the_orders_silently(): void
    {
        $orderUploader = $this->prophesize(OrderUploaderInterface::class);
        $orderUploader->upload()->willReturn(null)->shouldBeCalledOnce();

        $command = new UploadOrdersCommand($orderUploader->reveal());
        $commandTester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertSame('setono:sylius-shipmondo:upload-orders', $command->getName());
    }

    /**
     * @test
     *
     * @dataProvider outcomesThatStopTheUploadRun
     */
    public function it_fails_when_the_upload_run_stops_early(UploadOutcome $stoppedBy, string $reason): void
    {
        $orderUploader = $this->prophesize(OrderUploaderInterface::class);
        $orderUploader->upload()->willReturn($stoppedBy);

        $commandTester = new CommandTester(new UploadOrdersCommand($orderUploader->reveal()));

        self::assertSame(Command::FAILURE, $commandTester->execute([]));

        $display = (string) preg_replace('/\s+/', ' ', $commandTester->getDisplay());
        self::assertStringContainsString('[ERROR] ' . $reason . ' The remaining orders stay pending for the next run.', $display);
    }

    /**
     * @return iterable<string, array{UploadOutcome, string}>
     */
    public static function outcomesThatStopTheUploadRun(): iterable
    {
        yield 'credentials failure' => [
            UploadOutcome::CredentialsFailure,
            'The upload run stopped, because Shipmondo did not accept the API credentials. Check the Shipmondo API user and key.',
        ];
        yield 'rate limited' => [
            UploadOutcome::RateLimited,
            'The upload run stopped, because Shipmondo rate limited it.',
        ];
        yield 'three rejected uploads in a row' => [
            UploadOutcome::Rejected,
            'The upload run stopped after three rejected uploads in a row, which suggests a shop-wide problem, such as a payment gateway or shipment template that no longer exists in Shipmondo. The log has Shipmondo\'s errors.',
        ];
    }
}
