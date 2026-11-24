<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Failure;

use function Symfony\Component\String\u;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What an order keeps of a Shipmondo failure ({@see ShipmondoFailure}): its kind, Shipmondo's status code and the
 * failure's message, without the exception.
 *
 * Translated, it explains to an admin what went wrong, as the failure it records does
 */
final class RecordedShipmondoFailure implements TranslatableInterface
{
    /**
     * A longer message is cut off, ending in an ellipsis. It most likely holds Shipmondo's whole answer, e.g. an HTML
     * error page, which the log keeps
     */
    public const MAX_MESSAGE_LENGTH = 1000;

    /**
     * The failure's message, in UTF-8 and at most {@see self::MAX_MESSAGE_LENGTH} characters long: Shipmondo's own
     * message about the failure, if it sent one, e.g. which data it found invalid, or else the exception's
     */
    public readonly string $message;

    /**
     * @param int|null $statusCode the status code Shipmondo answered with, or null when Shipmondo didn't answer
     * @param string $message any bytes that aren't UTF-8 are replaced, so the message can be stored and shown
     */
    public function __construct(
        public readonly ShipmondoFailureKind $kind,
        public readonly ?int $statusCode,
        string $message,
    ) {
        $this->message = u(self::utf8($message))->truncate(self::MAX_MESSAGE_LENGTH, '…')->toString();
    }

    public static function of(ShipmondoFailure $failure): self
    {
        return new self($failure->kind, $failure->statusCode, $failure->error ?? $failure->exception->getMessage());
    }

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $translator->trans(
            'setono_sylius_shipmondo.shipmondo_failure.' . $this->kind->value,
            ['%error%' => $this->message],
            'messages',
            $locale,
        );
    }

    /**
     * A message that isn't UTF-8 keeps its ASCII characters, and every other byte becomes a question mark: which
     * encoding it is in is unknown
     */
    private static function utf8(string $message): string
    {
        return 1 === preg_match('//u', $message) ? $message : preg_replace('/[\x80-\xFF]/', '?', $message) ?? '';
    }
}
