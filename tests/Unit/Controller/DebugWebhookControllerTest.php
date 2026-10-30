<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Controller;

use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Controller\DebugWebhookController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final class DebugWebhookControllerTest extends TestCase
{
    use ProphecyTrait;

    private const KEY = 'test-webhook-key-with-at-least-32-bytes';

    /**
     * @test
     */
    public function it_renders_the_debug_page_in_the_dev_environment(): void
    {
        $twig = $this->prophesize(Environment::class);
        $twig->render('@SetonoSyliusShipmondoPlugin/shop/debug_webhook/index.html.twig')->willReturn('<h1>Debug</h1>');

        $response = (new DebugWebhookController($twig->reveal(), 'dev', self::KEY))->index();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('<h1>Debug</h1>', $response->getContent());
    }

    /**
     * @test
     */
    public function it_denies_the_debug_page_outside_the_dev_environment(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller('prod')->index();
    }

    /**
     * @test
     */
    public function it_decodes_a_signed_webhook_payload(): void
    {
        $jwt = JWT::encode(['data' => ['id' => 12345]], self::KEY, 'HS256');
        $request = self::ajaxRequest((string) json_encode(['data' => $jwt]));

        $response = $this->controller('dev')->decode($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"decoded":{"data":{"id":12345}}}', $response->getContent());
    }

    /**
     * @test
     */
    public function it_only_decodes_ajax_requests(): void
    {
        $response = $this->controller('dev')->decode(Request::create('/', 'POST', content: '{}'));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"error":"This route is only available via ajax"}', $response->getContent());
    }

    /**
     * @test
     */
    public function it_denies_decoding_outside_the_dev_environment(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller('test')->decode(self::ajaxRequest('{}'));
    }

    /**
     * @test
     */
    public function it_decodes_a_request_body_nested_up_to_the_default_json_depth(): void
    {
        $jwt = JWT::encode(['data' => []], self::KEY, 'HS256');

        // json_decode's default depth (512) accepts 511 levels of nesting, but not 512
        $response = $this->controller('dev')->decode(self::ajaxRequest(self::nestedBody($jwt, 511)));
        self::assertSame(200, $response->getStatusCode());

        $this->expectException(\JsonException::class);
        $this->controller('dev')->decode(self::ajaxRequest(self::nestedBody($jwt, 512)));
    }

    private function controller(string $environment): DebugWebhookController
    {
        return new DebugWebhookController($this->prophesize(Environment::class)->reveal(), $environment, self::KEY);
    }

    private static function ajaxRequest(string $content): Request
    {
        return Request::create('/', 'POST', server: ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], content: $content);
    }

    private static function nestedBody(string $jwt, int $levels): string
    {
        return sprintf('{"data":"%s","nested":%s%s}', $jwt, str_repeat('[', $levels - 1), str_repeat(']', $levels - 1));
    }
}
