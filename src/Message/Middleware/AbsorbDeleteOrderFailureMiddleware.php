<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Message\Middleware;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Message\Command\DeleteOrder;
use Setono\SyliusShipmondoPlugin\Message\CommandHandler\DeleteOrderHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Lets a failed sales order deletion fail only where failing is safe.
 *
 * A failing DeleteOrderHandler must not fail a cancellation: the command bus's doctrine_transaction middleware would roll
 * back its transaction, and on DBAL 3 without savepoints that marks an outer transaction, such as Sylius's cancellation,
 * rollback-only. So when the DeleteOrder command wasn't received from a transport (it is handled synchronously), or an
 * outer transaction is open, this middleware, which sits right inside doctrine_transaction, absorbs the failure: it logs
 * it and reports the sales order as not deleted, as the handler's result. A worker that received the command, with no
 * transaction around the bus's own, lets the failure through, so Messenger retries it or sends it to the failure transport.
 */
final class AbsorbDeleteOrderFailureMiddleware implements MiddlewareInterface
{
    /**
     * @param Connection $connection the one the bus's doctrine_transaction middleware begins its transaction on
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if (!$message instanceof DeleteOrder) {
            return $stack->next()->handle($envelope, $stack);
        }

        try {
            return $stack->next()->handle($envelope, $stack);
        } catch (HandlerFailedException $e) {
            if (null !== $envelope->last(ReceivedStamp::class) && !$this->isInOuterTransaction()) {
                throw $e;
            }

            $failure = array_values($e->getWrappedExceptions())[0] ?? $e;
            if ($failure instanceof UnrecoverableMessageHandlingException) {
                // the handler wraps a failure trying again won't change
                $failure = $failure->getPrevious() ?? $failure;
            }

            $this->logger->error('Could not delete the Shipmondo sales order of the order with the id {orderId}, so the order stays uploaded and linked to it. Delete the sales order in Shipmondo', [
                'orderId' => $message->order,
            ] + ShipmondoFailure::of($failure)->logContext());

            return $e->getEnvelope()->with(new HandledStamp(false, DeleteOrderHandler::class . '::__invoke'));
        }
    }

    /**
     * Whether a transaction is open around the one the bus's doctrine_transaction middleware began
     */
    private function isInOuterTransaction(): bool
    {
        return $this->connection->getTransactionNestingLevel() > 1;
    }
}
