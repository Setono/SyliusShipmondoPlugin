<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\RequestMatcher;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;

final class HasQueryParameterRequestMatcher implements RequestMatcherInterface
{
    public function __construct(private readonly string $parameter)
    {
    }

    public function matches(Request $request): bool
    {
        return $request->query->has($this->parameter);
    }
}
