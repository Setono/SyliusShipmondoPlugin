<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Controller;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;

final class DebugWebhookController
{
    public function __construct(
        private readonly Environment $twig,
        private readonly string $environment,
        private readonly string $webhooksKey,
    ) {
    }

    public function index(): Response
    {
        if ('dev' !== $this->environment) {
            throw new AccessDeniedHttpException('This route is only available in dev environment');
        }

        return new Response($this->twig->render('@SetonoSyliusShipmondoPlugin/shop/debug_webhook/index.html.twig'));
    }

    public function decode(Request $request): JsonResponse
    {
        if ('dev' !== $this->environment) {
            throw new AccessDeniedHttpException('This route is only available in dev environment');
        }

        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['error' => 'This route is only available via ajax'], Response::HTTP_BAD_REQUEST);
        }

        $json = $request->getContent();
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        $decoded = JWT::decode($data['data'], new Key($this->webhooksKey, 'HS256'));

        return new JsonResponse(['decoded' => $decoded]);
    }
}
