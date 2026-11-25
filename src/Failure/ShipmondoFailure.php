<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Failure;

use Setono\Shipmondo\Exception\ClientErrorException;
use Setono\Shipmondo\Exception\ConnectionException;
use Setono\Shipmondo\Exception\ForbiddenException;
use Setono\Shipmondo\Exception\MalformedResponseException;
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
final readonly class ShipmondoFailure implements TranslatableInterface
{
    /**
     * The status code Shipmondo answered with, or null when Shipmondo didn't answer
     */
    public ?int $statusCode;

    /**
     * Shipmondo's own message about the failure, if it sent one, e.g. which data it found invalid
     */
    public ?string $error;

    private function __construct(public ShipmondoFailureKind $kind, public \Throwable $exception)
    {
        $response = match (true) {
            $exception instanceof ResponseAwareException, $exception instanceof ConnectionException => $exception->getResponse(),
            default => null,
        };

        $this->statusCode = $response?->getStatusCode();
        $this->error = $exception instanceof ResponseAwareException ? $exception->getError() : null;
    }

    public static function of(\Throwable $exception): self
    {
        return new self(match (true) {
            $exception instanceof UnauthorizedException, $exception instanceof ForbiddenException => ShipmondoFailureKind::CredentialsFailure,
            $exception instanceof TooManyRequestsException => ShipmondoFailureKind::RateLimited,
            self::isUnreadSuccess($exception) => ShipmondoFailureKind::UnreadSuccess,
            // a read whose success was cut off is like one Shipmondo didn't answer: reading again changes nothing
            $exception instanceof ServerErrorException, $exception instanceof ConnectionException => ShipmondoFailureKind::TemporaryFailure,
            $exception instanceof NotFoundException => ShipmondoFailureKind::NotFound,
            $exception instanceof ClientErrorException => ShipmondoFailureKind::Rejected,
            default => ShipmondoFailureKind::UnexpectedFailure,
        }, $exception);
    }

    /**
     * Whether Shipmondo answered a call that changes something with a success, so it did what was asked, e.g. created a
     * sales order, but its answer was cut off, e.g. because it didn't arrive in time, or the SDK couldn't read it
     */
    private static function isUnreadSuccess(\Throwable $exception): bool
    {
        $request = match (true) {
            $exception instanceof ConnectionException && null !== $exception->getResponse() => $exception->getRequest(),
            // the SDK throws it only for a success
            $exception instanceof MalformedResponseException => $exception->getRequest(),
            default => null,
        };

        return null !== $request && 'GET' !== $request->getMethod();
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

    /**
     * Explains the failure as an order that recorded it does, with Shipmondo's message, or else the exception's
     */
    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return RecordedShipmondoFailure::of($this)->trans($translator, $locale);
    }
}
