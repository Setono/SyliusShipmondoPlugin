<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Failure;

use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Client\Client;
use Setono\Shipmondo\Exception\ConnectionException;
use Setono\Shipmondo\Exception\MalformedResponseException;
use Setono\Shipmondo\Exception\MappingException;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Translations;

final class ShipmondoFailureTest extends TestCase
{
    /**
     * @test
     *
     * @dataProvider answers
     */
    public function it_sorts_a_failed_call_by_how_shipmondo_answered(int $statusCode, ShipmondoFailureKind $kind): void
    {
        $failure = ShipmondoFailure::of(self::failedCall((new ScriptedHttpClient())->respond($statusCode, ['error' => 'Something is wrong'])));

        self::assertSame($kind, $failure->kind);
        self::assertSame($statusCode, $failure->statusCode);
        self::assertSame('Something is wrong', $failure->error);
    }

    /**
     * @return iterable<string, array{int, ShipmondoFailureKind}>
     */
    public static function answers(): iterable
    {
        yield '302 Found' => [302, ShipmondoFailureKind::UnexpectedFailure];
        yield '399' => [399, ShipmondoFailureKind::UnexpectedFailure];
        yield '400 Bad Request' => [400, ShipmondoFailureKind::Rejected];
        yield '401 Unauthorized' => [401, ShipmondoFailureKind::CredentialsFailure];
        yield '402 Payment Required' => [402, ShipmondoFailureKind::Rejected];
        yield '403 Forbidden' => [403, ShipmondoFailureKind::CredentialsFailure];
        yield '404 Not Found' => [404, ShipmondoFailureKind::NotFound];
        yield '409 Conflict' => [409, ShipmondoFailureKind::Rejected];
        yield '422 Unprocessable Entity' => [422, ShipmondoFailureKind::Rejected];
        yield '429 Too Many Requests' => [429, ShipmondoFailureKind::RateLimited];
        yield '499' => [499, ShipmondoFailureKind::Rejected];
        yield '500 Internal Server Error' => [500, ShipmondoFailureKind::TemporaryFailure];
        yield '503 Service Unavailable' => [503, ShipmondoFailureKind::TemporaryFailure];
    }

    /**
     * @test
     */
    public function it_is_a_temporary_failure_when_shipmondo_cannot_be_reached(): void
    {
        $exception = self::failedCall((new ScriptedHttpClient())->failToConnect());
        self::assertInstanceOf(ConnectionException::class, $exception);

        $failure = ShipmondoFailure::of($exception);

        self::assertSame(ShipmondoFailureKind::TemporaryFailure, $failure->kind);
        self::assertNull($failure->statusCode);
        self::assertNull($failure->error);
    }

    /**
     * Shipmondo did what was asked, so trying again could do it twice, e.g. create a second sales order
     *
     * @test
     *
     * @dataProvider callsThatChangeSomething
     *
     * @param \Closure(Client): mixed $call
     */
    public function it_is_an_unread_success_when_the_success_of_a_call_that_changes_something_is_cut_off(\Closure $call, int $statusCode): void
    {
        $exception = self::failedCall((new ScriptedHttpClient())->respondWithAnUnreadableBody($statusCode), $call);
        self::assertInstanceOf(ConnectionException::class, $exception);

        $failure = ShipmondoFailure::of($exception);

        self::assertSame(ShipmondoFailureKind::UnreadSuccess, $failure->kind);
        self::assertSame($statusCode, $failure->statusCode);
        self::assertNull($failure->error);
    }

    /**
     * @test
     *
     * @dataProvider callsThatChangeSomething
     *
     * @param \Closure(Client): mixed $call
     */
    public function it_is_an_unread_success_when_the_sdk_cannot_read_the_success_of_a_call_that_changes_something(\Closure $call, int $statusCode): void
    {
        $exception = self::failedCall((new ScriptedHttpClient())->respond($statusCode, body: 'not JSON'), $call);
        self::assertInstanceOf(MalformedResponseException::class, $exception);

        $failure = ShipmondoFailure::of($exception);

        self::assertSame(ShipmondoFailureKind::UnreadSuccess, $failure->kind);
        self::assertSame($statusCode, $failure->statusCode);
        self::assertNull($failure->error);
    }

    /**
     * @test
     */
    public function it_is_an_unread_success_when_the_sdk_cannot_map_the_sales_order_shipmondo_created(): void
    {
        $exception = self::failedCall((new ScriptedHttpClient())->respond(201, ['sales_order' => 1001]), self::createSalesOrder());
        self::assertInstanceOf(MappingException::class, $exception);

        self::assertSame(ShipmondoFailureKind::UnreadSuccess, ShipmondoFailure::of($exception)->kind);
    }

    /**
     * @return iterable<string, array{\Closure(Client): mixed, int}>
     */
    public static function callsThatChangeSomething(): iterable
    {
        yield 'creating a sales order' => [self::createSalesOrder(), 201];
        yield 'deleting a sales order' => [static function (Client $client): void {
            $client->salesOrders()->delete(1001);
        }, 200];
    }

    /**
     * Reading again changes nothing, so it is like getting no answer at all
     *
     * @test
     */
    public function it_is_a_temporary_failure_when_the_success_of_a_read_does_not_arrive_in_full(): void
    {
        $failure = ShipmondoFailure::of(self::failedCall((new ScriptedHttpClient())->respondWithAnUnreadableBody(200)));

        self::assertSame(ShipmondoFailureKind::TemporaryFailure, $failure->kind);
        self::assertSame(200, $failure->statusCode);
        self::assertNull($failure->error);
    }

    /**
     * @test
     */
    public function it_is_sorted_by_the_status_code_when_an_error_does_not_arrive_in_full(): void
    {
        $failure = ShipmondoFailure::of(self::failedCall((new ScriptedHttpClient())->respondWithAnUnreadableBody(503)));

        self::assertSame(ShipmondoFailureKind::TemporaryFailure, $failure->kind);
        self::assertSame(503, $failure->statusCode);
        self::assertNull($failure->error);
    }

    /**
     * @test
     */
    public function it_is_an_unexpected_failure_when_the_sdk_cannot_read_the_success_of_a_read(): void
    {
        $failure = ShipmondoFailure::of(self::failedCall((new ScriptedHttpClient())->respond(200, body: 'not JSON')));

        self::assertSame(ShipmondoFailureKind::UnexpectedFailure, $failure->kind);
        self::assertSame(200, $failure->statusCode);
        self::assertNull($failure->error);
    }

    /**
     * Without the request, nothing says whether the success answered a call that changes something
     *
     * @test
     */
    public function it_is_an_unexpected_failure_when_an_unreadable_success_does_not_say_which_call_it_answered(): void
    {
        $failure = ShipmondoFailure::of(new MalformedResponseException((new Psr17Factory())->createResponse(201)));

        self::assertSame(ShipmondoFailureKind::UnexpectedFailure, $failure->kind);
        self::assertSame(201, $failure->statusCode);
    }

    /**
     * @test
     */
    public function it_is_an_unexpected_failure_when_the_failure_is_not_shipmondos(): void
    {
        $exception = new \DivisionByZeroError('Division by zero');

        $failure = ShipmondoFailure::of($exception);

        self::assertSame(ShipmondoFailureKind::UnexpectedFailure, $failure->kind);
        self::assertSame($exception, $failure->exception);
        self::assertNull($failure->statusCode);
        self::assertNull($failure->error);
    }

    /**
     * @test
     */
    public function it_is_logged_with_its_kind_status_code_and_shipmondos_message(): void
    {
        $exception = self::failedCall((new ScriptedHttpClient())->respond(422, ['error' => 'Invalid or not found parameter(s)']));

        self::assertSame([
            'exception' => $exception,
            'shipmondoFailure' => 'rejected',
            'shipmondoStatusCode' => 422,
            'shipmondoError' => 'Invalid or not found parameter(s)',
        ], ShipmondoFailure::of($exception)->logContext());
    }

    /**
     * @test
     *
     * @dataProvider kinds
     */
    public function every_kind_explains_itself(ShipmondoFailureKind $kind, string $explanation): void
    {
        $statusCode = match ($kind) {
            ShipmondoFailureKind::CredentialsFailure => 401,
            ShipmondoFailureKind::RateLimited => 429,
            ShipmondoFailureKind::TemporaryFailure => 500,
            ShipmondoFailureKind::NotFound => 404,
            ShipmondoFailureKind::Rejected => 422,
            ShipmondoFailureKind::UnexpectedFailure => 302,
            // a created sales order that can't be read
            ShipmondoFailureKind::UnreadSuccess => 201,
        };

        $failure = ShipmondoFailure::of(self::failedCall(
            (new ScriptedHttpClient())->respond($statusCode, ['error' => 'Invalid or not found parameter(s)']),
            ShipmondoFailureKind::UnreadSuccess === $kind ? self::createSalesOrder() : null,
        ));

        self::assertSame($kind, $failure->kind);
        self::assertSame($explanation, $failure->trans(Translations::translator()));
    }

    /**
     * @return iterable<string, array{ShipmondoFailureKind, string}>
     */
    public static function kinds(): iterable
    {
        yield 'credentials failure' => [ShipmondoFailureKind::CredentialsFailure, 'Shipmondo did not accept the API credentials. Check SHIPMONDO_USERNAME and SHIPMONDO_KEY, and that SHIPMONDO_SANDBOX matches the Shipmondo account they belong to.'];
        yield 'rate limited' => [ShipmondoFailureKind::RateLimited, 'Shipmondo is limiting how often the store may call it. Try again in a minute.'];
        yield 'temporary failure' => [ShipmondoFailureKind::TemporaryFailure, 'Shipmondo could not be reached, or had a server error. Try again later.'];
        yield 'not found' => [ShipmondoFailureKind::NotFound, 'Shipmondo could not find what was asked for: Invalid or not found parameter(s)'];
        yield 'rejected' => [ShipmondoFailureKind::Rejected, 'Shipmondo refused the request: Invalid or not found parameter(s)'];
        yield 'unexpected failure' => [ShipmondoFailureKind::UnexpectedFailure, 'Something unexpected went wrong when calling Shipmondo: Invalid or not found parameter(s)'];
        yield 'unread success' => [ShipmondoFailureKind::UnreadSuccess, 'Shipmondo did what was asked, but its answer was cut off or could not be read: Invalid or not found parameter(s)'];
    }

    /**
     * @test
     */
    public function its_explanation_falls_back_to_the_exceptions_message_without_a_message_from_shipmondo(): void
    {
        $failure = ShipmondoFailure::of(new \RuntimeException('The data mapper failed'));

        self::assertSame('Something unexpected went wrong when calling Shipmondo: The data mapper failed', $failure->trans(Translations::translator()));
    }

    /**
     * @test
     */
    public function its_explanation_is_translated_to_the_given_locale(): void
    {
        $translator = Translations::translator();
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['setono_sylius_shipmondo.shipmondo_failure.rejected' => 'Shipmondo afviste kaldet: %error%'], 'da', 'messages');

        $failure = ShipmondoFailure::of(self::failedCall((new ScriptedHttpClient())->respond(422, ['error' => 'Ugyldig'])));

        self::assertSame('Shipmondo afviste kaldet: Ugyldig', $failure->trans($translator, 'da'));
    }

    /**
     * @return \Closure(Client): mixed
     */
    private static function createSalesOrder(): \Closure
    {
        return static fn (Client $client): mixed => $client->salesOrders()->create(new SalesOrderRequest(orderId: '000001001'));
    }

    /**
     * Calls Shipmondo through the real SDK, which answers from the script, and returns the exception the call threw.
     * The call reads a sales order, unless another is given
     *
     * @param (\Closure(Client): mixed)|null $call
     */
    private static function failedCall(ScriptedHttpClient $httpClient, ?\Closure $call = null): \Throwable
    {
        $call ??= static fn (Client $client): mixed => $client->salesOrders()->getById(1001);

        try {
            $call(ShipmondoApi::client($httpClient));
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('The call to Shipmondo did not fail');
    }
}
