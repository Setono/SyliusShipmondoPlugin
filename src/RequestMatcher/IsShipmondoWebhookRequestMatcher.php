<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\RequestMatcher;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final class IsShipmondoWebhookRequestMatcher implements RequestMatcherInterface
{
    public function matches(Request $request): bool
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return false;
        }

        return isset($data['data']) && is_string($data['data']);
    }
}
