<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\RequestMatcher;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

/**
 * Identifies an incoming Shipmondo webhook by the metadata headers Shipmondo sends with every
 * delivery. SMD-Resource-Type and SMD-Action are present on every delivery (including the
 * verification ping sent when a webhook is created), so they reliably distinguish a Shipmondo
 * webhook from other traffic hitting the endpoint.
 */
final class IsShipmondoWebhookRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        return $request->headers->has('SMD-Resource-Type') && $request->headers->has('SMD-Action');
    }
}
