<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Uploader;

interface OrderUploaderInterface
{
    /**
     * Uploads eligible orders to Shipmondo
     */
    public function upload(): void;
}
