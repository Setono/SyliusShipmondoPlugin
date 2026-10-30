<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Functional\Controller;

use Tests\Setono\SyliusShipmondoPlugin\Functional\FunctionalTestCase;

/**
 * The webhook debugging page decodes signed payloads with the webhook key, so outside the dev environment it
 * must not be reachable
 */
final class DebugWebhookControllerTest extends FunctionalTestCase
{
    /**
     * @test
     */
    public function it_denies_the_debug_page_outside_the_dev_environment(): void
    {
        $this->browser->request('GET', '/shipmondo/debug-webhook');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @test
     */
    public function it_denies_decoding_outside_the_dev_environment(): void
    {
        $this->browser->xmlHttpRequest('POST', '/shipmondo/debug-webhook/decode', content: '{"data": "a.b.c"}');

        self::assertResponseStatusCodeSame(403);
    }
}
