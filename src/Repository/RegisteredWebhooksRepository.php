<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Repository;

use Setono\SyliusShipmondoPlugin\Model\RegisteredWebhooksInterface;
use Sylius\Bundle\ResourceBundle\Doctrine\ORM\EntityRepository;
use Webmozart\Assert\Assert;

class RegisteredWebhooksRepository extends EntityRepository implements RegisteredWebhooksRepositoryInterface
{
    public function findLatest(): ?RegisteredWebhooksInterface
    {
        // of two registered at the same time, the one recorded last
        $obj = $this->findOneBy([], ['registeredAt' => 'DESC', 'id' => 'DESC']);
        Assert::nullOrIsInstanceOf($obj, RegisteredWebhooksInterface::class);

        return $obj;
    }
}
