<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Application\E2E;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

/**
 * Marks every response of the `e2e` environment, so the Playwright suite can verify it is talking to
 * an app whose Shipmondo API calls are stubbed before it runs (see tests/Playwright/global-setup.ts)
 */
final class StubMarkerListener
{
    public const HEADER = 'X-Shipmondo-Stub';

    public function __invoke(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set(self::HEADER, 'active');
        }
    }
}
