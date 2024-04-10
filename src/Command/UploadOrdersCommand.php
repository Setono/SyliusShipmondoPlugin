<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'setono:sylius-shipmondo:upload-orders',
    description: 'Upload orders to Shipmondo',
)]
final class UploadOrdersCommand extends Command
{
    public function __construct(private readonly OrderUploaderInterface $orderUploader)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->orderUploader->upload();

        return 0;
    }
}
