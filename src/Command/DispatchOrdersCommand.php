<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Setono\SyliusShipmondoPlugin\Dispatcher\OrderDispatcherInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'setono:sylius-shipmondo:dispatch-orders',
    description: 'Dispatch orders to Shipmondo',
)]
final class DispatchOrdersCommand extends Command
{
    public function __construct(private readonly OrderDispatcherInterface $orderDispatcher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->orderDispatcher->dispatch();

        return 0;
    }
}
