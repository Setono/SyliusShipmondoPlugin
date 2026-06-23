<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\RequestMatcher;

use PHPUnit\Framework\TestCase;
use Setono\SyliusShipmondoPlugin\RequestMatcher\IsShipmondoWebhookRequestMatcher;
use Symfony\Component\HttpFoundation\Request;

final class IsShipmondoWebhookRequestMatcherTest extends TestCase
{
    /**
     * @test
     */
    public function it_matches_a_request_carrying_the_shipmondo_headers(): void
    {
        self::assertTrue($this->matchesHeaders(['HTTP_SMD_RESOURCE_TYPE' => 'Orders', 'HTTP_SMD_ACTION' => 'delete']));
    }

    /**
     * @test
     */
    public function it_does_not_match_without_the_action_header(): void
    {
        self::assertFalse($this->matchesHeaders(['HTTP_SMD_RESOURCE_TYPE' => 'Orders']));
    }

    /**
     * @test
     */
    public function it_does_not_match_without_the_resource_type_header(): void
    {
        self::assertFalse($this->matchesHeaders(['HTTP_SMD_ACTION' => 'delete']));
    }

    /**
     * @test
     */
    public function it_does_not_match_without_any_shipmondo_headers(): void
    {
        self::assertFalse($this->matchesHeaders([]));
    }

    /**
     * @param array<string, string> $server
     */
    private function matchesHeaders(array $server): bool
    {
        return (new IsShipmondoWebhookRequestMatcher())->matches(
            Request::create('/webhook/shipmondo', 'POST', server: $server),
        );
    }
}
