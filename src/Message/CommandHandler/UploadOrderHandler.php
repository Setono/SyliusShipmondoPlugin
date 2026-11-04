<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\CommandHandler;

use Setono\SyliusShipmondoPlugin\Message\Command\UploadOrder;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\UploadOutcome;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Webmozart\Assert\Assert;

/**
 * Uploads the order the command names. The upload settles the order's Shipmondo state itself and never throws because
 * it failed, so Messenger doesn't retry it: the next upload run is the retry. Returns the outcome, which the upload run
 * reads when the command is handled synchronously.
 */
final class UploadOrderHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderUploaderInterface $orderUploader,
    ) {
    }

    public function __invoke(UploadOrder $message): UploadOutcome
    {
        $order = $this->orderRepository->find($message->order);
        if (null === $order) {
            throw new UnrecoverableMessageHandlingException(sprintf('Order with id %s does not exist', $message->order));
        }

        Assert::isInstanceOf($order, OrderInterface::class);

        return $this->orderUploader->uploadOrder($order, $message->version);
    }
}
