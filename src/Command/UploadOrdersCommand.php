<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\UploadOutcome;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs an upload run. It is silent when the run gets through every order, and fails when the run stops early, or
 * Shipmondo rate limits its sales order lookups, so a cron job's monitoring notices a problem that keeps every order from
 * being uploaded or looked up. A single failed upload or lookup is logged and shows on the order.
 */
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
        $stoppedBy = $this->orderUploader->upload();
        if (null === $stoppedBy) {
            return Command::SUCCESS;
        }

        (new SymfonyStyle($input, $output))->getErrorStyle()->error(match ($stoppedBy) {
            UploadOutcome::CredentialsFailure => 'The upload run stopped, because Shipmondo did not accept the API credentials. Check the Shipmondo API user and key.',
            // it may have stopped only the sales order lookups, and uploaded the pending orders
            UploadOutcome::RateLimited => 'Shipmondo rate limited the upload run, so it did not get through every order.',
            default => 'The upload run stopped after three rejected uploads in a row, which suggests a shop-wide problem, such as a payment gateway or shipment template that no longer exists in Shipmondo. The log has Shipmondo\'s errors.',
        } . ' The remaining orders wait for the next run.');

        return Command::FAILURE;
    }
}
