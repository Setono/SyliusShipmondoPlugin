<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Failure;

use PHPUnit\Framework\TestCase;
use Setono\Shipmondo\Exception\ConnectionException;
use Setono\Shipmondo\Exception\ValidationException;
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
     * @test
     */
    public function it_is_an_unexpected_failure_when_the_sdk_cannot_read_a_successful_answer(): void
    {
        $failure = ShipmondoFailure::of(self::failedCall((new ScriptedHttpClient())->respond(200, body: 'not JSON')));

        self::assertSame(ShipmondoFailureKind::UnexpectedFailure, $failure->kind);
        self::assertSame(200, $failure->statusCode);
        self::assertNull($failure->error);
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
        $statusCode = [
            'credentials_failure' => 401,
            'rate_limited' => 429,
            'temporary_failure' => 500,
            'not_found' => 404,
            'rejected' => 422,
            'unexpected_failure' => 302,
        ][$kind->value];

        $failure = ShipmondoFailure::of(self::failedCall((new ScriptedHttpClient())->respond($statusCode, ['error' => 'Invalid or not found parameter(s)'])));

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

        $failure = ShipmondoFailure::of(new ValidationException(ShipmondoApi::json(['error' => 'Ugyldig'], 422)));

        self::assertSame('Shipmondo afviste kaldet: Ugyldig', $failure->trans($translator, 'da'));
    }

    /**
     * Calls Shipmondo through the real SDK, which answers from the script, and returns the exception the call threw
     */
    private static function failedCall(ScriptedHttpClient $httpClient): \Throwable
    {
        try {
            ShipmondoApi::client($httpClient)->salesOrders()->getById(1001);
        } catch (\Throwable $e) {
            return $e;
        }

        self::fail('The call to Shipmondo did not fail');
    }
}
