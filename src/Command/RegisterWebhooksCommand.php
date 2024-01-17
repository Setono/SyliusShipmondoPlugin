<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Setono\SyliusShipmondoPlugin\Registrar\WebhookRegistrarInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'setono:sylius-shipmondo:register-webhooks',
    description: 'Register relevant webhooks with Shipmondo',
)]
final class RegisterWebhooksCommand extends Command
{
    public function __construct(private readonly WebhookRegistrarInterface $webhookRegistrar)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->webhookRegistrar->register();

        return 0;
    }
}
