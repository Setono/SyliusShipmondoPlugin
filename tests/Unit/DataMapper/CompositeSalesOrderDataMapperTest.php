<?php

declare(strict_types=1);

namespace Tests\Setono\SyliusShipmondoPlugin\Unit\DataMapper;

use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\EventDispatcher\EventDispatcherInterface;
use Setono\Shipmondo\Request\SalesOrder\SalesOrderRequest;
use Setono\SyliusShipmondoPlugin\DataMapper\CompositeSalesOrderDataMapper;
use Setono\SyliusShipmondoPlugin\DataMapper\SalesOrderDataMapperInterface;
use Setono\SyliusShipmondoPlugin\Event\SalesOrderMappedEvent;
use Setono\SyliusShipmondoPlugin\Model\OrderInterface;

final class CompositeSalesOrderDataMapperTest extends TestCase
{
    use ProphecyTrait;

    /**
     * @test
     */
    public function it_runs_every_mapper_in_order_and_dispatches_an_event_afterwards(): void
    {
        $order = $this->prophesize(OrderInterface::class)->reveal();
        $salesOrder = new SalesOrderRequest();

        /** @var list<string> $calls */
        $calls = [];

        $first = $this->prophesize(SalesOrderDataMapperInterface::class);
        $first->map($order, $salesOrder)->will(static function () use (&$calls): void {
            $calls[] = 'first';
        });

        $second = $this->prophesize(SalesOrderDataMapperInterface::class);
        $second->map($order, $salesOrder)->will(static function () use (&$calls): void {
            $calls[] = 'second';
        });

        $eventDispatcher = $this->prophesize(EventDispatcherInterface::class);
        $eventDispatcher->dispatch(Argument::that(
            static fn (object $event): bool => $event instanceof SalesOrderMappedEvent && $event->order === $order && $event->salesOrder === $salesOrder,
        ))->will(static function (array $args) use (&$calls): object {
            $calls[] = 'event';

            $event = $args[0];
            self::assertIsObject($event);

            return $event;
        });

        $mapper = new CompositeSalesOrderDataMapper($eventDispatcher->reveal());
        $mapper->add($first->reveal());
        $mapper->add($second->reveal());

        $mapper->map($order, $salesOrder);

        self::assertSame(['first', 'second', 'event'], $calls);
    }
}
