<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Model;

use Sylius\Component\Core\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Resource\Model\VersionedInterface;

interface OrderInterface extends BaseOrderInterface, VersionedInterface
{
    public const SHIPMONDO_STATE_PENDING = 'pending';

    public const SHIPMONDO_STATE_UPLOADING_TO_SHIPMONDO = 'uploading_to_shipmondo';

    public const SHIPMONDO_STATE_UPLOADED_TO_SHIPMONDO = 'uploaded_to_shipmondo';

    public const SHIPMONDO_STATE_FAILED = 'failed';

    public function getShipmondoState(): string;

    public function setShipmondoState(string $shipmondoState): void;
}
