<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin;

/** @psalm-suppress UndefinedClass,MixedArgument */
if (!\function_exists(formatAmount::class)) {
    function formatAmount(int $amount): string
    {
        return (string) round($amount / 100, 2);
    }
}
