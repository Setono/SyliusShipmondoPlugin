<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Repository;

use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooksInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Webmozart\Assert\Assert;

class RegisteredWebhooksRepository extends EntityRepository implements RegisteredWebhooksRepositoryInterface
{
    public function findOneByHash(string $hash): ?RegisteredWebhooksInterface
    {
        $obj = $this->findOneBy(['hash' => $hash]);
        Assert::nullOrIsInstanceOf($obj, RegisteredWebhooksInterface::class);

        return $obj;
    }
}
