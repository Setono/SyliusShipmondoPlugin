<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Support;

use Setono\SyliusShipmondoPlugin\Webhook\Handler\OrderWebhookHandlers;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Webmozart\Assert\Assert;

/**
 * Shipmondo's webhooks as the test application's plugin wants them
 */
final class Webhooks
{
    /**
     * The webhooks the plugin wants at the endpoint, as Shipmondo lists them: one per action its webhook handlers handle
     * on the orders resource, named after the shop's id, which is derived from its webhooks key
     *
     * @return list<array{id: int, endpoint: string, active: bool, name: string, action: string, resource_name: string}>
     */
    public static function wanted(ContainerInterface $container, string $endpoint, int $firstId = 9001): array
    {
        $handlers = $container->get(OrderWebhookHandlers::class);
        Assert::isInstanceOf($handlers, OrderWebhookHandlers::class);

        $webhooksKey = $container->getParameter('setono_sylius_shipmondo.webhooks.key');
        Assert::string($webhooksKey);
        $namePrefix = $container->getParameter('setono_sylius_shipmondo.webhooks.name_prefix');
        Assert::string($namePrefix);
        $shopId = substr(hash_hmac('sha256', 'shop id', $webhooksKey), 0, 8);

        $webhooks = [];
        foreach ($handlers->subscribedActions() as $action) {
            $webhooks[] = [
                'id' => $firstId + count($webhooks),
                'endpoint' => $endpoint,
                'active' => true,
                'name' => sprintf('%s [%s] - [orders:%s]', $namePrefix, $shopId, $action->value),
                'action' => $action->value,
                'resource_name' => 'Orders',
            ];
        }

        return $webhooks;
    }
}
