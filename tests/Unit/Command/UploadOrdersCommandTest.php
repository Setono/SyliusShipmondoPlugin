<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Command\UploadOrdersCommand;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\UploadRunProblem;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Setono\SyliusShipmondoPlugin\Support\CommandErrors;

final class UploadOrdersCommandTest extends TestCase
{
    use ProphecyTrait;

    private const CREDENTIALS_FAILURE = 'The upload run stopped, because Shipmondo did not accept the API credentials. Check the Shipmondo API user and key. The remaining orders wait for the next run.';

    private const LOOKUPS_RATE_LIMITED = 'The sales order lookups stopped, because Shipmondo rate limited one. If it keeps happening, check the unlinked orders in Shipmondo: an order with several sales orders is looked up in every run. The pending orders were uploaded, and the remaining unlinked orders wait for the next run.';

    private const WEBHOOKS_NOT_REGISTERED_YET = 'The upload run did not register the webhooks again, because they are not registered yet: no registration with an endpoint is recorded. Register them once from the admin\'s Shipmondo page or with setono:sylius-shipmondo:register-webhooks, after which the upload run keeps them current. To keep them yourself, turn off setono_sylius_shipmondo.webhooks.register_automatically. The pending orders were uploaded.';

    private const UNREAD_SUCCESSES_IN_A_ROW = 'Shipmondo answered three uploads in a row with a success that was cut off or could not be read, so it created the sales orders, but the orders are unlinked until sales order lookups link them, at least ten minutes later. Its answers can\'t be read or arrive too late: check the log, the time limits of the requests to Shipmondo and the version of the Shipmondo SDK.';

    /**
     * @test
     */
    public function it_uploads_the_orders_silently(): void
    {
        $orderUploader = $this->prophesize(OrderUploaderInterface::class);
        $orderUploader->upload()->willReturn([])->shouldBeCalledOnce();

        $command = new UploadOrdersCommand($orderUploader->reveal());
        $commandTester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertSame('', $commandTester->getDisplay());
        self::assertSame('setono:sylius-shipmondo:upload-orders', $command->getName());
    }

    /**
     * Whatever runs the command, e.g. cron, notices a problem that most likely hits every order
     *
     * @test
     *
     * @dataProvider problems
     */
    public function it_fails_and_says_why_when_the_upload_run_reports_a_problem(UploadRunProblem $problem, string $error): void
    {
        $orderUploader = $this->prophesize(OrderUploaderInterface::class);
        $orderUploader->upload()->willReturn([$problem]);

        $commandTester = new CommandTester(new UploadOrdersCommand($orderUploader->reveal()));

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertSame([$error], CommandErrors::of($commandTester));
    }

    /**
     * @return iterable<string, array{UploadRunProblem, string}>
     */
    public static function problems(): iterable
    {
        yield 'credentials failure' => [UploadRunProblem::CredentialsFailure, self::CREDENTIALS_FAILURE];
        yield 'rate limited' => [
            UploadRunProblem::UploadRateLimited,
            'The upload run stopped, because Shipmondo rate limited an upload. If it keeps happening, check what else calls Shipmondo with the same API user. The remaining orders wait for the next run.',
        ];
        yield 'temporary failures in a row' => [
            UploadRunProblem::TemporaryFailuresInARow,
            'The upload run stopped after three temporary failures in a row: Shipmondo answered with a server error, or not in time. Check whether Shipmondo is down, and the log. The remaining orders wait for the next run.',
        ];
        yield 'failed uploads in a row' => [
            UploadRunProblem::FailedUploadsInARow,
            'The upload run stopped after three uploads in a row that made the order failed, which suggests a shop-wide problem, such as a payment gateway or shipment template that no longer exists in Shipmondo, or a data mapper that fails for every order. The log has the errors. The remaining orders wait for the next run.',
        ];
        yield 'lookups rate limited' => [UploadRunProblem::LookupsRateLimited, self::LOOKUPS_RATE_LIMITED];
        yield 'failed lookups in a row' => [
            UploadRunProblem::FailedLookupsInARow,
            'The sales order lookups stopped after three lookups in a row that failed: Shipmondo rejected them, or its answers could not be read. Check the log and the version of the Shipmondo SDK. The pending orders were uploaded, and the remaining unlinked orders wait for the next run.',
        ];
        yield 'unread successes in a row' => [UploadRunProblem::UnreadSuccessesInARow, self::UNREAD_SUCCESSES_IN_A_ROW];
        yield 'webhooks not registered yet' => [UploadRunProblem::WebhooksNotRegisteredYet, self::WEBHOOKS_NOT_REGISTERED_YET];
        yield 'webhook endpoint unreachable' => [
            UploadRunProblem::WebhookEndpointUnreachable,
            'The upload run did not register the webhooks again, because Shipmondo can\'t reach the endpoint they were last registered at, which the log names. Register them from the admin\'s Shipmondo page, opened at the shop\'s hostname, or with setono:sylius-shipmondo:register-webhooks. The pending orders were uploaded.',
        ];
        yield 'webhook registration failed' => [
            UploadRunProblem::WebhookRegistrationFailed,
            'The upload run could not compare the webhooks with Shipmondo\'s, or register them again, because a call to Shipmondo failed. The log has why. The next run tries again, and the pending orders were uploaded.',
        ];
    }

    /**
     * @test
     */
    public function it_names_every_problem_the_upload_run_reports(): void
    {
        $orderUploader = $this->prophesize(OrderUploaderInterface::class);
        $orderUploader->upload()->willReturn([UploadRunProblem::LookupsRateLimited, UploadRunProblem::UnreadSuccessesInARow, UploadRunProblem::CredentialsFailure, UploadRunProblem::WebhooksNotRegisteredYet]);

        $commandTester = new CommandTester(new UploadOrdersCommand($orderUploader->reveal()));

        self::assertSame(Command::FAILURE, $commandTester->execute([]));
        self::assertSame([self::LOOKUPS_RATE_LIMITED, self::UNREAD_SUCCESSES_IN_A_ROW, self::CREDENTIALS_FAILURE, self::WEBHOOKS_NOT_REGISTERED_YET], CommandErrors::of($commandTester));
    }
}
