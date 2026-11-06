<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Failure;

use Setono\Shipmondo\Exception\ClientErrorException;
use Setono\Shipmondo\Exception\ConnectionException;
use Setono\Shipmondo\Exception\ForbiddenException;
use Setono\Shipmondo\Exception\NotFoundException;
use Setono\Shipmondo\Exception\ResponseAwareException;
use Setono\Shipmondo\Exception\ServerErrorException;
use Setono\Shipmondo\Exception\TooManyRequestsException;
use Setono\Shipmondo\Exception\UnauthorizedException;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A call to Shipmondo that didn't succeed, sorted into one kind ({@see ShipmondoFailureKind}) by the SDK exception it
 * threw. Each caller decides what the kind means for it: an upload settles the order's Shipmondo state by it, the
 * admin explains it.
 *
 * Translated, it explains to an admin why the call failed and what to do about it, e.g. which credentials to check.
 * It is logged with {@see self::logContext()}.
 */
final class ShipmondoFailure implements TranslatableInterface
{
    /**
     * The status code Shipmondo answered with, or null when Shipmondo didn't answer
     */
    public readonly ?int $statusCode;

    /**
     * Shipmondo's own message about the failure, if it sent one, e.g. which data it found invalid
     */
    public readonly ?string $error;

    private function __construct(public readonly ShipmondoFailureKind $kind, public readonly \Throwable $exception)
    {
        $this->statusCode = $exception instanceof ResponseAwareException ? $exception->getResponse()->getStatusCode() : null;
        $this->error = $exception instanceof ResponseAwareException ? $exception->getError() : null;
    }

    public static function of(\Throwable $exception): self
    {
        return new self(match (true) {
            $exception instanceof UnauthorizedException, $exception instanceof ForbiddenException => ShipmondoFailureKind::CredentialsFailure,
            $exception instanceof TooManyRequestsException => ShipmondoFailureKind::RateLimited,
            $exception instanceof ServerErrorException, $exception instanceof ConnectionException => ShipmondoFailureKind::TemporaryFailure,
            $exception instanceof NotFoundException => ShipmondoFailureKind::NotFound,
            $exception instanceof ClientErrorException => ShipmondoFailureKind::Rejected,
            default => ShipmondoFailureKind::UnexpectedFailure,
        }, $exception);
    }

    /**
     * @return array{exception: \Throwable, shipmondoFailure: string, shipmondoStatusCode: int|null, shipmondoError: string|null}
     */
    public function logContext(): array
    {
        return [
            'exception' => $this->exception,
            'shipmondoFailure' => $this->kind->value,
            'shipmondoStatusCode' => $this->statusCode,
            'shipmondoError' => $this->error,
        ];
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(
            'setono_sylius_shipmondo.shipmondo_failure.' . $this->kind->value,
            ['%error%' => $this->error ?? $this->exception->getMessage()],
            'messages',
            $locale,
        );
    }
}
