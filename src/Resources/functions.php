<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin;

if (!\function_exists('formatAmount')) {
    function formatAmount(int $amount): string
    {
        return (string) round($amount / 100, 2);
    }
}
