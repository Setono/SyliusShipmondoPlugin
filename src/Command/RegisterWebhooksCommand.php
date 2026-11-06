<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Psr\Log\LoggerInterface;
use Setono\Shipmondo\Exception\ShipmondoException;
use Setono\SyliusShipmondoPlugin\Failure\ShipmondoFailure;
use Setono\SyliusShipmondoPlugin\Message\Command\RegisterWebhooks;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(
    name: 'setono:sylius-shipmondo:register-webhooks',
    description: 'Register relevant webhooks with Shipmondo',
)]
final class RegisterWebhooksCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    /**
     * Fails, and says why, when Shipmondo fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->commandBus->dispatch(new RegisterWebhooks());
        } catch (HandlerFailedException $e) {
            $failure = ShipmondoFailure::of(array_values($e->getWrappedExceptions(ShipmondoException::class))[0] ?? throw $e);

            $this->logger->error('The webhooks could not be registered in Shipmondo', $failure->logContext());
            (new SymfonyStyle($input, $output))->getErrorStyle()->error($this->translator->trans(
                'setono_sylius_shipmondo.webhooks_not_registered',
                ['%reason%' => $failure->trans($this->translator)],
                'flashes',
            ));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
