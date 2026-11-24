<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Support;

use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reads the error block a console command printed through SymfonyStyle
 */
final class CommandErrors
{
    /**
     * The messages of the error block, each with its line breaks and padding folded into single spaces
     *
     * @return list<string>
     */
    public static function of(CommandTester $tester): array
    {
        $display = trim($tester->getDisplay());
        Assert::assertStringStartsWith('[ERROR] ', $display);

        $messages = preg_split('/\n\s*\n/', trim(substr($display, strlen('[ERROR] '))));
        Assert::assertIsArray($messages);

        return array_map(static fn (string $message): string => trim((string) preg_replace('/\s+/', ' ', $message)), $messages);
    }
}
