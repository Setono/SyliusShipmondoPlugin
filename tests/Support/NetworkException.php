<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Support;

use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;

/**
 * What a PSR-18 client throws when it can't reach Shipmondo, e.g. because of a network problem or a timeout
 */
final class NetworkException extends \RuntimeException implements NetworkExceptionInterface
{
    public function __construct(private readonly RequestInterface $request)
    {
        parent::__construct('Could not connect to Shipmondo');
    }

    public function getRequest(): RequestInterface
    {
        return $this->request;
    }
}
