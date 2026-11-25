<?php

declare(strict_types=1);

namespace Setono\SyliusShipmondoPlugin\Command;

use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Setono\SyliusShipmondoPlugin\Uploader\UploadRunProblem;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Runs an upload run. It is silent when the run reports no problem, and fails, naming each problem the run reports and
 * what to do about it, when the run stopped early, its sales order lookups stopped, or it hit three unread successes in a
 * row, so a cron job's monitoring notices a problem that most likely hits every order. A single failed upload or lookup
 * is logged and shows on the order.
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
        $problems = $this->orderUploader->upload();
        if ([] === $problems) {
            return Command::SUCCESS;
        }

        (new SymfonyStyle($input, $output))->getErrorStyle()->error(array_map(
            static fn (UploadRunProblem $problem): string => match ($problem) {
                UploadRunProblem::CredentialsFailure => 'The upload run stopped, because Shipmondo did not accept the API credentials. Check the Shipmondo API user and key. The remaining orders wait for the next run.',
                UploadRunProblem::UploadRateLimited => 'The upload run stopped, because Shipmondo rate limited an upload. If it keeps happening, check what else calls Shipmondo with the same API user. The remaining orders wait for the next run.',
                UploadRunProblem::TemporaryFailuresInARow => 'The upload run stopped after three temporary failures in a row: Shipmondo answered with a server error, or not in time. Check whether Shipmondo is down, and the log. The remaining orders wait for the next run.',
                UploadRunProblem::FailedUploadsInARow => 'The upload run stopped after three uploads in a row that made the order failed, which suggests a shop-wide problem, such as a payment gateway or shipment template that no longer exists in Shipmondo, or a data mapper that fails for every order. The log has the errors. The remaining orders wait for the next run.',
                UploadRunProblem::LookupsRateLimited => 'The sales order lookups stopped, because Shipmondo rate limited one. If it keeps happening, check the unlinked orders in Shipmondo: an order with several sales orders is looked up in every run. The pending orders were uploaded, and the remaining unlinked orders wait for the next run.',
                UploadRunProblem::FailedLookupsInARow => 'The sales order lookups stopped after three lookups in a row that failed: Shipmondo rejected them, or its answers could not be read. Check the log and the version of the Shipmondo SDK. The pending orders were uploaded, and the remaining unlinked orders wait for the next run.',
                UploadRunProblem::UnreadSuccessesInARow => 'Shipmondo answered three uploads in a row with a success that was cut off or could not be read, so it created the sales orders, but the orders are unlinked until sales order lookups link them, at least ten minutes later. Its answers can\'t be read or arrive too late: check the log, the time limits of the requests to Shipmondo and the version of the Shipmondo SDK.',
            },
            $problems,
        ));

        return Command::FAILURE;
    }
}
