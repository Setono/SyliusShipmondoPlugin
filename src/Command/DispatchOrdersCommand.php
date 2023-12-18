<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Setono\SyliusShipmondoPlugin\Dispatcher\OrderDispatcherInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DispatchOrdersCommand extends Command
{
    protected static $defaultName = 'setono:sylius-shipmondo:dispatch-orders';

    protected static $defaultDescription = 'Dispatch orders to Shipmondo';

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
