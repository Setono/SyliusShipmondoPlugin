<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\Command;

use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Setono\SyliusShipmondoPlugin\Command\UploadOrdersCommand;
use Setono\SyliusShipmondoPlugin\Uploader\OrderUploaderInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UploadOrdersCommandTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_uploads_the_orders(): void
    {
        $orderUploader = $this->prophesize(OrderUploaderInterface::class);
        $orderUploader->upload()->shouldBeCalledOnce();

        $command = new UploadOrdersCommand($orderUploader->reveal());
        $commandTester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $commandTester->execute([]));
        self::assertSame('setono:sylius-shipmondo:upload-orders', $command->getName());
    }
}
