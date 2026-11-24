<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\JsonType;
use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\Doctrine\Type\RecordedShipmondoFailureType;
use Setono\SyliusShipmondoPlugin\Failure\RecordedShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailureKind;

final class RecordedShipmondoFailureTypeTest extends TestCase
{
    private const JSON = '{"kind":"rejected","statusCode":422,"message":"The payment gateway does not exist"}';

    /**
     * @test
     */
    public function it_stores_a_shipmondo_failure_as_json(): void
    {
        self::assertSame(self::JSON, self::type()->convertToDatabaseValue(self::failure(), self::platform()));
    }

    /**
     * @test
     */
    public function it_stores_a_shipmondo_failure_without_a_status_code(): void
    {
        $failure = new RecordedShipmondoFailure(ShipmondoFailureKind::UnexpectedFailure, null, 'Division by zero');

        self::assertSame('{"kind":"unexpected_failure","statusCode":null,"message":"Division by zero"}', self::type()->convertToDatabaseValue($failure, self::platform()));
    }

    /**
     * @test
     */
    public function it_stores_no_shipmondo_failure_as_null(): void
    {
        self::assertNull(self::type()->convertToDatabaseValue(null, self::platform()));
    }

    /**
     * @test
     */
    public function it_does_not_store_anything_but_a_shipmondo_failure(): void
    {
        $this->expectException(ConversionException::class);
        $this->expectExceptionMessage('Expected one of the following types: null, Setono\\SyliusShipmondoPlugin\\Failure\\RecordedShipmondoFailure');

        self::type()->convertToDatabaseValue(['kind' => 'rejected'], self::platform());
    }

    /**
     * @test
     */
    public function it_reads_a_stored_shipmondo_failure(): void
    {
        self::assertEquals(self::failure(), self::type()->convertToPHPValue(self::JSON, self::platform()));
    }

    /**
     * @test
     */
    public function it_reads_a_stored_shipmondo_failure_without_a_status_code(): void
    {
        self::assertEquals(
            new RecordedShipmondoFailure(ShipmondoFailureKind::TemporaryFailure, null, 'Could not get a response from Shipmondo'),
            self::type()->convertToPHPValue('{"kind":"temporary_failure","statusCode":null,"message":"Could not get a response from Shipmondo"}', self::platform()),
        );
    }

    /**
     * @test
     */
    public function it_reads_a_shipmondo_failure_the_driver_reads_as_a_stream(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, self::JSON);
        rewind($stream);

        self::assertEquals(self::failure(), self::type()->convertToPHPValue($stream, self::platform()));
    }

    /**
     * The order must still load everywhere, e.g. after a downgrade of the plugin, or an edit in the database
     *
     * @test
     *
     * @dataProvider noShipmondoFailure
     */
    public function it_reads_a_value_it_cannot_read_as_a_shipmondo_failure_as_null(?string $value): void
    {
        self::assertNull(self::type()->convertToPHPValue($value, self::platform()));
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function noShipmondoFailure(): iterable
    {
        yield 'null' => [null];
        yield 'an empty string' => [''];
        yield 'not JSON' => ['rejected'];
        yield 'not a JSON object' => ['"rejected"'];
        yield 'no kind' => ['{"statusCode":422,"message":"Invalid"}'];
        yield 'a kind that is not a string' => ['{"kind":422,"statusCode":422,"message":"Invalid"}'];
        yield 'a kind this version does not know' => ['{"kind":"conflict","statusCode":409,"message":"Invalid"}'];
    }

    /**
     * What can be read of it is kept, so the admin still learns its kind
     *
     * @test
     *
     * @dataProvider incompleteShipmondoFailures
     */
    public function it_reads_what_it_can_of_an_incomplete_shipmondo_failure(string $value, ?int $statusCode, string $message): void
    {
        self::assertEquals(
            new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, $statusCode, $message),
            self::type()->convertToPHPValue($value, self::platform()),
        );
    }

    /**
     * @return iterable<string, array{string, int|null, string}>
     */
    public static function incompleteShipmondoFailures(): iterable
    {
        yield 'no status code' => ['{"kind":"rejected","message":"Invalid"}', null, 'Invalid'];
        yield 'a status code that is not a number' => ['{"kind":"rejected","statusCode":"422","message":"Invalid"}', null, 'Invalid'];
        yield 'no message' => ['{"kind":"rejected","statusCode":422}', 422, ''];
        yield 'a message that is not a string' => ['{"kind":"rejected","statusCode":422,"message":["Invalid"]}', 422, ''];
    }

    /**
     * @test
     */
    public function it_is_a_json_column(): void
    {
        self::assertSame((new JsonType())->getSQLDeclaration([], self::platform()), self::type()->getSQLDeclaration([], self::platform()));
    }

    /**
     * The comment lets the schema tool tell the column from a plain JSON column, so it doesn't change the column
     * on every run (DBAL 2, and DBAL 3 on platforms without inline column comments)
     *
     * @test
     */
    public function it_is_named_in_the_column_comment(): void
    {
        self::assertSame('setono_sylius_shipmondo_recorded_shipmondo_failure', self::type()->getName());
        self::assertTrue(self::type()->requiresSQLCommentHint(self::platform()));
        self::assertTrue(self::type()->requiresSQLCommentHint(new PostgreSQLPlatform()));
    }

    private static function failure(): RecordedShipmondoFailure
    {
        return new RecordedShipmondoFailure(ShipmondoFailureKind::Rejected, 422, 'The payment gateway does not exist');
    }

    private static function type(): RecordedShipmondoFailureType
    {
        return new RecordedShipmondoFailureType();
    }

    private static function platform(): AbstractPlatform
    {
        return new MySQLPlatform();
    }
}
