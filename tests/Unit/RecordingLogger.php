<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit;

use Psr\Log\AbstractLogger;

/**
 * Keeps every log record, so a test can assert on what was logged
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<array-key, mixed>}> */
    public array $records = [];

    /**
     * Untyped to fit every psr/log version's signature
     *
     * @param array<array-key, mixed> $context
     */
    public function log(mixed $level, mixed $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
