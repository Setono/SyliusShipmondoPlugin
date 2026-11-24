<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Failure;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Failure\RecordedShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ScriptedHttpClient;
use Tests\Setono\SyliusShipmondoPlugin\Unit\ShipmondoApi;
use Tests\Setono\SyliusShipmondoPlugin\Unit\Translations;

final class RecordedShipmondoFailureTest extends TestCase
{
    /**
     * @test
     */
    public function it_keeps_the_kind_status_code_and_message_of_a_failure_without_its_exception(): void
    {
        $failure = RecordedShipmondoFailure::of(ShipmondoFailure::of(self::failedCall(
            (new ScriptedHttpClient())->respond(422, ['error' => 'The payment gateway does not exist']),
        )));

        self::assertSame(ShipmondoFailureKind::Rejected, $failure->kind);
        self::assertSame(422, $failure->statusCode);
        self::assertSame('The payment gateway does not exist', $failure->message);
    }

    /**
     * @test
     */
    public function it_keeps_the_exceptions_message_of_a_failure_without_a_message_from_shipmondo(): void
    {
        $failure = RecordedShipmondoFailure::of(ShipmondoFailure::of(new \DivisionByZeroError('Division by zero')));

        self::assertSame(ShipmondoFailureKind::UnexpectedFailure, $failure->kind);
        self::assertNull($failure->statusCode);
        self::assertSame('Division by zero', $failure->message);
    }

    /**
     * @test
     */
    public function it_keeps_a_message_of_up_to_a_thousand_characters_whole(): void
    {
        $message = str_repeat('æ', 1000);

        self::assertSame($message, (new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 422, $message))->message);
    }

    /**
     * Without Shipmondo's message, the exception's holds Shipmondo's whole answer, e.g. a proxy's HTML error page
     *
     * @test
     */
    public function it_cuts_a_longer_message_off_at_a_thousand_characters(): void
    {
        $failure = new RecordedShipmondoFailure(ShipmondoFailureKind::TemporaryFailure, 502, str_repeat('æ', 1001));

        self::assertSame(str_repeat('æ', 999) . '…', $failure->message);
    }

    /**
     * The order's column holds UTF-8, so a message with other bytes, e.g. from an answer in another encoding, couldn't be
     * stored, and the upload couldn't be settled
     *
     * @test
     */
    public function it_replaces_the_bytes_of_a_message_that_are_not_utf_8(): void
    {
        $failure = new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 400, "Ugyldig v\xE6rdi \xFF");

        self::assertSame('Ugyldig v?rdi ?', $failure->message);
    }

    /**
     * An admin reads the same explanation on the order page as in the flashes and on the admin Shipmondo page
     *
     * @test
     *
     * @dataProvider \Tests\Setono\SyliusShipmondoPlugin\Unit\Failure\ShipmondoFailureTest::kinds
     */
    public function every_kind_explains_itself_as_the_failure_it_records(ShipmondoFailureKind $kind, string $explanation): void
    {
        $failure = new RecordedShipmondoFailure($kind, 422, 'Invalid or not found parameter(s)');

        self::assertSame($explanation, $failure->trans(Translations::translator()));
    }

    /**
     * @test
     */
    public function its_explanation_is_translated_to_the_given_locale(): void
    {
        $translator = Translations::translator();
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['setono_sylius_shipmondo.shipmondo_failure.rejected' => 'Shipmondo afviste kaldet: %error%'], 'da', 'messages');

        $failure = new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 422, 'Ugyldig');

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
